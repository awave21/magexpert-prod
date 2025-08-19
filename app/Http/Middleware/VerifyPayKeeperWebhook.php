<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyPayKeeperWebhook
{
    /**
     * Проверить webhook от PayKeeper
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Проверяем, что PayKeeper включен
            if (!config('paykeeper.enabled', false)) {
                Log::warning('PayKeeper Webhook: PayKeeper отключен');
                return response()->json(['error' => 'PayKeeper disabled'], 400);
            }

            // Базовая проверка наличия обязательных полей
            if (!$this->hasRequiredFields($request)) {
                Log::warning('PayKeeper Webhook: Отсутствуют обязательные поля', [
                    'request_data' => $request->all()
                ]);
                return response()->json(['error' => 'Missing required fields'], 400);
            }

            // Проверка IP адреса (если настроено)
            if (!$this->isValidIP($request)) {
                Log::warning('PayKeeper Webhook: Недопустимый IP адрес', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                return response()->json(['error' => 'Invalid IP address'], 403);
            }

            // Проверка подписи (если настроена)
            if (!$this->verifySignature($request)) {
                Log::warning('PayKeeper Webhook: Неверная подпись');
                return response()->json(['error' => 'Invalid signature'], 403);
            }

            // Логируем успешную валидацию
            Log::info('PayKeeper Webhook: Валидация прошла успешно', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

        } catch (\Exception $e) {
            Log::error('PayKeeper Webhook Middleware: Ошибка валидации', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);
            
            return response()->json(['error' => 'Validation failed'], 400);
        }

        return $next($request);
    }

    /**
     * Проверить наличие обязательных полей согласно документации PayKeeper
     *
     * @param Request $request
     * @return bool
     */
    protected function hasRequiredFields(Request $request): bool
    {
        // Согласно документации PayKeeper обязательные поля: id, sum, key
        $requiredFields = ['id', 'sum', 'key'];
        
        foreach ($requiredFields as $field) {
            if (!$request->has($field) || empty($request->get($field))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверить IP адрес отправителя
     *
     * @param Request $request
     * @return bool
     */
    protected function isValidIP(Request $request): bool
    {
        // Получаем список разрешенных IP адресов из конфига
        $allowedIPs = config('paykeeper.allowed_ips', []);

        // Если список не настроен, разрешаем все IP (для разработки)
        if (empty($allowedIPs)) {
            return true;
        }

        $requestIP = $request->ip();

        // Проверяем точное совпадение IP
        if (in_array($requestIP, $allowedIPs)) {
            return true;
        }

        // Проверяем подсети (CIDR)
        foreach ($allowedIPs as $allowedIP) {
            if (str_contains($allowedIP, '/')) {
                if ($this->ipInCIDR($requestIP, $allowedIP)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверить, находится ли IP в заданной подсети CIDR
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    protected function ipInCIDR(string $ip, string $cidr): bool
    {
        list($subnet, $bits) = explode('/', $cidr);
        
        if ($bits === null) {
            $bits = 32;
        }

        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;

        return ($ip & $mask) == $subnet;
    }

    /**
     * Проверить цифровую подпись webhook согласно документации PayKeeper
     *
     * @param Request $request
     * @return bool
     */
    protected function verifySignature(Request $request): bool
    {
        $secret = config('paykeeper.webhook_secret');

        // Если секрет не настроен, пропускаем проверку подписи
        if (empty($secret)) {
            return true;
        }

        // Получаем данные из POST запроса
        $id = $request->get('id');
        $sum = $request->get('sum');
        $clientid = $request->get('clientid', '');
        $orderid = $request->get('orderid', '');
        $key = $request->get('key');

        if (empty($key)) {
            return false;
        }

        // Формируем строку для проверки подписи согласно документации PayKeeper:
        // md5(id + formatted_sum + clientid + orderid + secret_seed)
        $formattedSum = number_format((float)$sum, 2, '.', '');
        $expectedKey = md5($id . $formattedSum . $clientid . $orderid . $secret);

        return hash_equals($expectedKey, $key);
    }
}