<?php

namespace App\Logging;

use Illuminate\Database\QueryException;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Throwable;

/**
 * Короткое сообщение об ошибке: окружение и хост, класс исключения, сообщение, файл:строка, запрос без query-строки
 * и id пользователя. Без стека, тела запроса, cookies, заголовков. Email, телефоны, длинные токены и SQL
 * из текста ошибки вырезаются.
 */
class TelegramFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $lines = ['🔴 '.app()->environment().' · '.$host.' · '.$record->level->getName()];

        if ($exception instanceof Throwable) {
            $lines[] = $exception::class;
            $lines[] = self::clean(self::message($exception));
            $lines[] = self::relativePath($exception->getFile()).':'.$exception->getLine();
        } else {
            $lines[] = self::clean($record->message);
        }

        $lines[] = self::where();

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  array<LogRecord>  $records
     */
    public function formatBatch(array $records): string
    {
        return implode("\n\n", array_map(fn (LogRecord $record): string => $this->format($record), $records));
    }

    /**
     * Чем одна ошибка отличается от другой для дедупликации: класс, место и первая строка текста.
     */
    public static function fingerprint(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            return $record->message;
        }

        return $exception::class.' '.self::relativePath($exception->getFile()).':'.$exception->getLine().' '.strtok(self::clean(self::message($exception)), "\n");
    }

    /**
     * Вырезает то, что не должно уходить в чат: email, телефоны, длинные токены и ключи.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = (string) preg_replace('/(?<!\d)(?:\+7|8|7)[\s\-(]*\d{3}[\s\-)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)|\+\d{10,14}(?!\d)/', '[телефон]', $text);
        $text = (string) preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[скрыто]', $text);

        return trim($text);
    }

    /**
     * У ошибок базы в тексте весь SQL со значениями (пароли, email, телефоны): оставляем только код и причину.
     */
    private static function message(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if ($exception instanceof QueryException) {
            $message = (string) preg_replace('/\s*\(Connection: .*$/s', '', $message);
        }

        return $message;
    }

    private static function relativePath(string $file): string
    {
        return str_starts_with($file, base_path().'/') ? substr($file, strlen(base_path()) + 1) : $file;
    }

    /**
     * Где случилось: метод и адрес без query-строки и id пользователя, или консоль (очередь, cron, artisan).
     */
    private static function where(): string
    {
        try {
            $request = request();

            if ($request->route() === null) {
                return 'консоль';
            }

            $user = auth()->id();

            return $request->method().' '.$request->url().($user !== null ? ' · пользователь #'.$user : '');
        } catch (Throwable) {
            return '';
        }
    }
}
