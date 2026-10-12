<?php

namespace App\Sender\Http\Middleware;

use App\Sender\Models\ApiKey;
use App\Sender\Models\AuditLog;
use App\Sender\Models\Campaign;
use App\Sender\Models\Contact;
use App\Sender\Models\ContactList;
use App\Sender\Models\Domain;
use App\Sender\Models\SenderAddress;
use App\Sender\Models\Suppression;
use App\Sender\Models\Template;
use App\Sender\Models\User;
use App\Sender\Models\Webhook;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Журнал действий: успешные изменения в админке записываются с автором и объектом.
 * Название объекта берётся до запроса, чтобы оно было и у удалённых.
 */
class RecordAuditLog
{
    /**
     * Маршрут => что сделано. Остальные изменения (черновики, папки, переменные) в журнал не пишутся.
     */
    public const ACTIONS = [
        'campaigns.send' => 'Запустил рассылку',
        'campaigns.pause' => 'Поставил рассылку на паузу',
        'campaigns.resume' => 'Продолжил рассылку',
        'campaigns.cancel' => 'Отменил рассылку',
        'campaigns.destroy' => 'Удалил рассылку',
        'campaigns.test' => 'Отправил тест рассылки',
        'lists.store' => 'Создал базу',
        'lists.destroy' => 'Удалил базу',
        'lists.sync' => 'Обновил базу из источника',
        'contacts.import' => 'Загрузил адреса в базу',
        'contacts.destroy' => 'Удалил подписчика',
        'templates.store' => 'Создал письмо',
        'templates.update' => 'Изменил письмо',
        'templates.destroy' => 'Удалил письмо',
        'api-keys.store' => 'Создал API-ключ',
        'api-keys.destroy' => 'Отозвал API-ключ',
        'domains.store' => 'Добавил домен',
        'domains.destroy' => 'Удалил домен',
        'sender-addresses.store' => 'Добавил адрес отправителя',
        'sender-addresses.destroy' => 'Удалил адрес отправителя',
        'suppressions.store' => 'Заблокировал адрес',
        'suppressions.destroy' => 'Разблокировал адрес',
        'webhooks.store' => 'Добавил вебхук',
        'webhooks.destroy' => 'Удалил вебхук',
        'users.store' => 'Добавил пользователя',
        'users.update' => 'Изменил доступ пользователя',
        'profile.password' => 'Сменил свой пароль',
        'organization.update' => 'Переименовал организацию',
        'organization.transfer' => 'Передал владение организацией',
        // действия ИИ-ассистента по ключу MCP: пишутся из app/Sender/Mcp, не маршрутами админки
        'mcp.templates.store' => 'Создал письмо через ИИ',
        'mcp.templates.update' => 'Изменил письмо через ИИ',
        'mcp.templates.test' => 'Отправил тест через ИИ',
        'mcp.tokens.store' => 'Создал ключ для ИИ-ассистента',
        'mcp.tokens.destroy' => 'Отозвал ключ для ИИ-ассистента',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $action = Str::after((string) $request->route()?->getName(), 'sender.admin.');

        if (! array_key_exists($action, self::ACTIONS)) {
            return $next($request);
        }

        $subject = $this->subject($request, $action);
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $user = $request->attributes->get('sender_user');

            AuditLog::query()->create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'action' => $action,
                'subject' => $subject !== null ? Str::limit($subject, 195) : null,
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    private function subject(Request $request, string $action): ?string
    {
        $user = $request->attributes->get('sender_user');
        $find = fn (string $model, string $param, string $column) => ($id = $request->route($param)) !== null
            ? $model::query()->where('organization_id', $user->organization_id)->whereKey((int) $id)->value($column)
            : null;

        return match (Str::before($action, '.')) {
            'campaigns' => $find(Campaign::class, 'campaign', 'name'),
            'lists' => $find(ContactList::class, 'list', 'name') ?? $request->input('name'),
            'contacts' => $action === 'contacts.destroy' ? $find(Contact::class, 'contact', 'email') : $find(ContactList::class, 'list', 'name'),
            'templates' => $find(Template::class, 'template', 'name') ?? $request->input('name'),
            'api-keys' => $find(ApiKey::class, 'apiKey', 'name') ?? $request->input('name'),
            'domains' => $find(Domain::class, 'domain', 'domain') ?? $request->input('domain'),
            'sender-addresses' => $find(SenderAddress::class, 'address', 'email') ?? $request->input('email'),
            'suppressions' => $find(Suppression::class, 'suppression', 'email') ?? $request->input('email'),
            'webhooks' => $find(Webhook::class, 'webhook', 'url') ?? $request->input('url'),
            'users' => $find(User::class, 'user', 'email') ?? $request->input('email'),
            'organization' => $action === 'organization.transfer'
                ? User::query()->where('organization_id', $user->organization_id)->whereKey((int) $request->input('user_id'))->value('email')
                : $request->input('name'),
            default => null,
        };
    }
}
