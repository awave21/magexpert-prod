<?php

namespace App\Sender\Console;

use App\Sender\Models\Organization;
use App\Sender\Services\MessageService;
use Illuminate\Console\Command;

/**
 * Организации Sender: список, дневной лимит писем, включение и отключение.
 */
class OrganizationCommand extends Command
{
    protected $signature = 'sender:organization {slug? : slug организации; без него — список всех}
        {--daily-limit= : сколько писем в сутки может отправить организация}
        {--no-limit : снять дневной лимит}
        {--activate : разрешить вход и отправку}
        {--deactivate : запретить вход и отправку}';

    protected $description = 'Показать организации, задать дневной лимит писем, включить или отключить организацию';

    public function handle(MessageService $messages): int
    {
        $slug = $this->argument('slug');

        if ($slug === null) {
            $this->table(
                ['Slug', 'Название', 'Активна', 'Лимит в сутки', 'Отправлено сегодня'],
                Organization::query()->orderBy('id')->get()->map(fn (Organization $o): array => [
                    $o->slug, $o->name, $o->is_active ? 'да' : 'нет', $o->daily_limit ?? 'без лимита', $messages->sentToday($o),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            $this->error("Организация '{$slug}' не найдена");

            return self::FAILURE;
        }

        if ($this->option('daily-limit') !== null) {
            $limit = (int) $this->option('daily-limit');

            if ($limit < 1) {
                $this->error('Лимит должен быть положительным числом. Снять лимит: --no-limit');

                return self::FAILURE;
            }

            $organization->daily_limit = $limit;
        }

        if ($this->option('no-limit')) {
            $organization->daily_limit = null;
        }

        if ($this->option('activate')) {
            $organization->is_active = true;
        }

        if ($this->option('deactivate')) {
            $organization->is_active = false;
        }

        $organization->save();

        $this->info(sprintf(
            '%s: %s, лимит %s, отправлено сегодня %d',
            $organization->name,
            $organization->is_active ? 'активна' : 'отключена',
            $organization->daily_limit ? $organization->daily_limit.' писем в сутки' : 'без лимита',
            $messages->sentToday($organization),
        ));

        return self::SUCCESS;
    }
}
