<?php

namespace App\Sender\Console;

use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Services\DomainService;
use Illuminate\Console\Command;

class DomainCommand extends Command
{
    protected $signature = 'sender:domain {domain} {--organization= : slug организации} {--verify : проверить DNS}';

    protected $description = 'Добавляет домен отправителя, показывает DNS-записи и проверяет их';

    public function handle(DomainService $service): int
    {
        $slug = $this->option('organization') ?: config('sender.client.organization');
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            $this->error("Организация '{$slug}' не найдена. Сначала выполните sender:install-defaults");

            return self::FAILURE;
        }

        $name = strtolower((string) $this->argument('domain'));
        $domain = Domain::query()->where('domain', $name)->first() ?? $service->add($organization, $name);

        $this->table(
            ['Запись', 'Тип', 'Имя', 'Значение', 'Обязательна'],
            collect($service->dnsRecords($domain))->map(fn (array $record): array => [
                $record['key'], $record['type'], $record['host'], $record['value'], $record['required'] ? 'да' : 'нет',
            ])->all(),
        );

        if ($this->option('verify')) {
            $report = $service->verify($domain);

            foreach ($report as $key => $ok) {
                $this->line(($ok ? '[ок]  ' : '[нет] ').$key);
            }

            $this->info('Статус домена: '.$domain->fresh()->status);
        } else {
            $this->line('Статус домена: '.$domain->status.'. Добавьте записи в DNS и запустите с --verify.');
        }

        return self::SUCCESS;
    }
}
