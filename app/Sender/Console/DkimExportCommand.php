<?php

namespace App\Sender\Console;

use App\Sender\Models\Domain;
use Illuminate\Console\Command;

class DkimExportCommand extends Command
{
    protected $signature = 'sender:dkim-export {domain} {path : куда записать приватный ключ} {--force : перезаписать существующий файл}';

    protected $description = 'Записывает приватный ключ DKIM домена в файл (для OpenDKIM на почтовом сервере)';

    public function handle(): int
    {
        $domain = Domain::query()->where('domain', strtolower((string) $this->argument('domain')))->first();

        if ($domain === null || $domain->dkim_private_key === null) {
            $this->error('Домен или ключ DKIM не найдены. Сначала выполните sender:domain');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');

        if (file_exists($path) && ! $this->option('force')) {
            $this->error("Файл {$path} уже существует (используйте --force)");

            return self::FAILURE;
        }

        file_put_contents($path, $domain->dkim_private_key);
        chmod($path, 0600);

        $this->info("Ключ записан в {$path} (права 600), селектор: {$domain->dkim_selector}");

        return self::SUCCESS;
    }
}
