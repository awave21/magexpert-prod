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

        $lines = 0;
        $matched = 0;
        $rotated = ! $this->option('from-start') && isset($state['inode']) && $state['inode'] !== $inode;

        // журнал повернули (logrotate): сначала дочитываем хвост старого файла, он теперь mail.log.1
        if ($rotated && is_readable($file.'.1') && (int) fileinode($file.'.1') === $state['inode']) {
            [, $read, $found] = $this->read($file.'.1', (int) $state['offset'], $log, true);
            $lines += $read;
            $matched += $found;
        }

        // новый файл после поворота или обрезанный — с начала
        $offset = ! $this->option('from-start') && ! $rotated && ($state['offset'] ?? 0) <= $size
            ? (int) ($state['offset'] ?? 0)
            : 0;

        [$offset, $read, $found] = $this->read($file, $offset, $log);
        $lines += $read;
        $matched += $found;

        Cache::forever($key, ['inode' => $inode, 'offset' => $offset]);

        $this->info("Прочитано строк: {$lines}, о наших письмах: {$matched}");

        return self::SUCCESS;
    }

    /**
     * @param  bool  $toEnd  старый файл больше не пишется: последняя строка без перевода строки тоже полная
     * @return array{0: int, 1: int, 2: int} смещение после чтения, прочитано строк, строк о наших письмах
     */
    private function read(string $file, int $offset, MailLogService $log, bool $toEnd = false): array
    {
        $handle = fopen($file, 'r');
        fseek($handle, $offset);
        $lines = 0;
        $matched = 0;

        while (($line = fgets($handle)) !== false) {
            // неполная последняя строка: дочитаем в следующий раз
            if (! $toEnd && ! str_ends_with($line, "\n")) {
                break;
            }

            $offset += strlen($line);
            $lines++;
            $matched += (int) $log->handle($line);
        }

        fclose($handle);

        return [$offset, $lines, $matched];
    }
}
