<?php

namespace App\Mcp\Support;

use App\Mcp\ToolException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Достаёт файл из аргумента инструмента: либо по ссылке https (url), либо из base64. Закрытые адреса и переадресации не пускаем.
 */
class MediaSource
{
    public const MAX_IMAGE = 15 * 1024 * 1024;

    public const MAX_FILE = 20 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public static function schema(string $description): array
    {
        return [
            'type' => 'object',
            'description' => $description.' Передайте либо url (https), либо base64.',
            'properties' => [
                'url' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Ссылка https на файл'],
                'base64' => ['type' => 'string', 'description' => 'Содержимое файла в base64'],
                'filename' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Имя файла с расширением (нужно для документов)'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{bytes: string, filename: string}
     */
    public static function fetch(array $source, int $maxBytes): array
    {
        $hasUrl = ! empty($source['url']);
        $hasBase64 = ! empty($source['base64']);

        if ($hasUrl === $hasBase64) {
            throw new ToolException('Передайте ровно одно из: url или base64.');
        }

        $bytes = $hasUrl ? self::download((string) $source['url'], $maxBytes) : self::decode((string) $source['base64'], $maxBytes);
        $name = (string) ($source['filename'] ?? ($hasUrl ? basename((string) parse_url($source['url'], PHP_URL_PATH)) : 'file'));

        return ['bytes' => $bytes, 'filename' => $name !== '' ? $name : 'file'];
    }

    private static function decode(string $base64, int $maxBytes): string
    {
        $base64 = preg_replace('~^data:[^,]*,~', '', trim($base64));
        $bytes = base64_decode((string) $base64, true);

        if ($bytes === false || $bytes === '') {
            throw new ToolException('Не удалось прочитать base64.');
        }
        if (strlen($bytes) > $maxBytes) {
            throw new ToolException('Файл больше '.intdiv($maxBytes, 1048576).' МБ.');
        }

        return $bytes;
    }

    private static function download(string $url, int $maxBytes): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            throw new ToolException('Ссылка должна начинаться с https://');
        }

        $ip = filter_var($parts['host'], FILTER_VALIDATE_IP) ? $parts['host'] : gethostbyname($parts['host']);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new ToolException('Адрес недоступен для загрузки.');
        }

        try {
            $response = Http::timeout(20)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable) {
            throw new ToolException('Не удалось скачать файл по ссылке.');
        }

        if (! $response->successful()) {
            throw new ToolException('Ссылка вернула ошибку '.$response->status().' (переадресации не поддерживаются: дайте прямую ссылку на файл).');
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > $maxBytes) {
            throw new ToolException('Файл пустой или больше '.intdiv($maxBytes, 1048576).' МБ.');
        }

        return $body;
    }
}
