<?php

namespace App\Sender\Console;

use App\Sender\Models\Organization;
use Illuminate\Console\Command;

class InstallDefaultsCommand extends Command
{
    protected $signature = 'sender:install-defaults {--organization= : slug организации}';

    protected $description = 'Создаёт организацию и шаблоны писем по умолчанию (повторный запуск обновляет шаблоны)';

    public function handle(): int
    {
        $slug = $this->option('organization') ?: config('sender.client.organization');

        $organization = Organization::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        foreach ($this->templates() as $template) {
            $organization->templates()->updateOrCreate(['slug' => $template['slug']], $template);
            $this->line("Шаблон: {$template['slug']}");
        }

        $this->info("Организация '{$slug}' готова.");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, string>>
     */
    private function templates(): array
    {
        return [
            [
                'slug' => 'event-registration',
                'name' => 'Регистрация на мероприятие',
                'subject' => 'Вы зарегистрированы: {{ event_title }}',
                'body_html' => <<<'HTML'
<p>Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!</p>
{{#if event_image}}<p><img src="{{ event_image }}" alt="{{ event_title }}" width="560" style="max-width:100%;height:auto"></p>{{/if}}
<p>Вы зарегистрированы на мероприятие «{{ event_title }}».</p>
{{#if event_type}}<p>Тип: {{ event_type }}</p>{{/if}}
{{#if event_format}}<p>Формат: {{ event_format }}</p>{{/if}}
{{#if start_date}}<p>Дата: {{ start_date }}{{#if start_time}}, {{ start_time }}{{/if}}</p>{{/if}}
{{#if event_city}}<p>Город: {{ event_city }}</p>{{/if}}
{{#if event_location}}<p>Место: {{ event_location }}</p>{{/if}}
{{#if price}}<p>Стоимость: {{ price }}</p>{{/if}}
{{#if speakers}}<p><strong>Спикеры</strong></p>
{{#each speakers}}<p>{{#if photo}}<img src="{{ photo }}" alt="{{ name }}" width="64" height="64" style="border-radius:32px;vertical-align:middle"> {{/if}}{{ name }}{{#if regalia}}, {{ regalia }}{{/if}}{{#if position}}, {{ position }}{{/if}}</p>
{{/each}}{{/if}}
{{#if set_password_url}}<p>Мы создали для вас учётную запись ({{ user_email }}). <a href="{{ set_password_url }}">Задать пароль</a>: ссылка действует {{ set_password_expires_in }} мин.</p>{{/if}}
<p><a href="{{ event_url }}">Страница мероприятия</a></p>
HTML,
                'body_text' => <<<'TEXT'
Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!

Вы зарегистрированы на мероприятие «{{ event_title }}».
{{#if start_date}}Дата: {{ start_date }} {{ start_time }}{{/if}}
{{#if event_location}}Место: {{ event_location }}{{/if}}
{{#if set_password_url}}Мы создали для вас учётную запись ({{ user_email }}). Задать пароль: {{ set_password_url }} (ссылка действует {{ set_password_expires_in }} мин.){{/if}}

Страница мероприятия: {{ event_url }}
TEXT,
            ],
            [
                'slug' => 'api-registration',
                'name' => 'Регистрация через API',
                'subject' => 'Ваша учётная запись создана',
                'body_html' => <<<'HTML'
<p>Здравствуйте{{#if name}}, {{ name }}{{/if}}!</p>
<p>Для вас создана учётная запись ({{ user_email }}).</p>
<p><a href="{{ set_password_url }}">Задать пароль</a>: ссылка действует {{ set_password_expires_in }} мин.</p>
HTML,
                'body_text' => <<<'TEXT'
Здравствуйте{{#if name}}, {{ name }}{{/if}}!

Для вас создана учётная запись ({{ user_email }}).
Задать пароль: {{ set_password_url }} (ссылка действует {{ set_password_expires_in }} мин.)
TEXT,
            ],
        ];
    }
}
