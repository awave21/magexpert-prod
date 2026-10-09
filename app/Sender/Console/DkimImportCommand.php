<?php

namespace App\Sender\Console;

use App\Sender\Models\Domain;
use Illuminate\Console\Command;

class DkimImportCommand extends Command
{
    protected $signature = 'sender:dkim-import {domain} {path : файл с приватным ключом (PEM)} {--selector= : селектор DKIM}';

    protected $description = 'Загружает существующий приватный ключ DKIM домена (например, созданный на почтовом сервере)';

    public function handle(): int
    {
        $domain = Domain::query()->where('domain', strtolower((string) $this->argument('domain')))->first();

        if ($domain === null) {
            $this->error('Домен не найден. Сначала выполните sender:domain');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $pem = is_readable($path) ? file_get_contents($path) : false;
        $key = $pem === false ? false : openssl_pkey_get_private($pem);

        if ($key === false) {
            $this->error("Не удалось прочитать приватный ключ из {$path}");

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        $public = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $details['key']);

        $domain->forceFill(array_filter([
            'dkim_private_key' => $pem,
            'dkim_public_key' => $public,
            'dkim_selector' => $this->option('selector'),
        ]))->save();

        $this->info('Ключ DKIM загружен. Значение для DNS: v=DKIM1; k=rsa; p='.$public);

        return self::SUCCESS;
    }
}
