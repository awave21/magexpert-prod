<?php

namespace App\Sender\Console;

use App\Sender\Models\Organization;
use Illuminate\Console\Command;

/**
 * Загружает шаблоны писем из JSON (например, deploy/templates/welcome.json) в организацию.
 * Существующий шаблон с тем же ключом не меняется, пока не указан --force: его могли отредактировать в интерфейсе.
 */
class TemplateImportCommand extends Command
{
    protected $signature = 'sender:template-import {file : JSON со списком шаблонов} {--organization= : Ключ организации (по умолчанию SENDER_ORGANIZATION)} {--force : Перезаписать шаблон с тем же ключом}';

    protected $description = 'Загрузить шаблоны писем из файла';

    public function handle(): int
    {
        $organization = Organization::query()->where('slug', $this->option('organization') ?: config('sender.client.organization'))->first();

        if ($organization === null) {
            $this->error('Организация не найдена');

            return self::FAILURE;
        }

        $templates = json_decode((string) @file_get_contents((string) $this->argument('file')), true);

        if (! is_array($templates)) {
            $this->error('Не удалось прочитать файл: нужен JSON-массив шаблонов');

            return self::FAILURE;
        }

        foreach ($templates as $data) {
            $slug = (string) ($data['slug'] ?? '');
            $existing = $organization->templates()->where('slug', $slug)->first();

            if ($existing !== null && ! $this->option('force')) {
                $this->line("«{$slug}» уже есть — пропущен (чтобы перезаписать, добавьте --force)");

                continue;
            }

            // отправителя подставляем, только если такой подтверждённый адрес уже есть в организации
            $sender = isset($data['sender_email'])
                ? $organization->senderAddresses()->where('email', $data['sender_email'])->whereNotNull('confirmed_at')->first()
                : null;

            $organization->templates()->updateOrCreate(['slug' => $slug], [
                'name' => $data['name'],
                'subject' => $data['subject'],
                'preheader' => $data['preheader'] ?? null,
                'reply_to' => $data['reply_to'] ?? null,
                'body_html' => $data['body_html'],
                'body_text' => $data['body_text'] ?? null,
                'editor' => $data['editor'] ?? 'html',
                'design' => $data['design'] ?? null,
                'sender_address_id' => $sender?->id ?? $existing?->sender_address_id,
            ]);

            $this->info(($existing ? 'Обновлён' : 'Создан')." шаблон «{$slug}»".($sender ? ", отправитель {$sender->email}" : ', отправителя выберите в интерфейсе'));
        }

        return self::SUCCESS;
    }
}
