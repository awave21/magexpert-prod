<?php

namespace App\Sender\Console;

use App\Sender\Services\MailLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Читает новые строки журнала Postfix с того места, где остановился в прошлый раз.
 * Запускается планировщиком каждую минуту.
 */
class MailLogCommand extends Command
{
    protected $signature = 'sender:mail-log {file? : Журнал Postfix} {--from-start : Прочитать файл с начала}';

    protected $description = 'Обновить статусы доставки писем из журнала Postfix';

    public function handle(MailLogService $log): int
    {
        $file = (string) ($this->argument('file') ?: config('sender.mail_log'));

        if (! is_readable($file)) {
            $this->error("Нет доступа к {$file}: добавьте пользователя в группу adm (sudo usermod -aG adm <пользователь>)");

            return self::FAILURE;
        }

        clearstatcache(true, $file);
        $key = 'sender.mail-log.'.md5($file);
        $state = Cache::get($key, []);
        $inode = (int) fileinode($file);
        $size = (int) filesize($file);

        // журнал повернули (logrotate) или обрезали: читаем новый файл с начала
        $offset = ! $this->option('from-start') && ($state['inode'] ?? null) === $inode && ($state['offset'] ?? 0) <= $size
            ? (int) $state['offset']
            : 0;

        $handle = fopen($file, 'r');
        fseek($handle, $offset);
        $lines = 0;
        $matched = 0;

        while (($line = fgets($handle)) !== false) {
            // неполная последняя строка: дочитаем в следующий раз
            if (! str_ends_with($line, "\n")) {
                break;
            }

            $offset += strlen($line);
            $lines++;
            $matched += (int) $log->handle($line);
        }

        fclose($handle);
        Cache::forever($key, ['inode' => $inode, 'offset' => $offset]);

        $this->info("Прочитано строк: {$lines}, о наших письмах: {$matched}");

        return self::SUCCESS;
    }
}
