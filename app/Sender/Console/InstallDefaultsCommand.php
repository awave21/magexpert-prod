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
{{#if speaker_1_name}}<p><strong>Спикеры</strong></p>
<p>{{#if speaker_1_photo}}<img src="{{ speaker_1_photo }}" alt="{{ speaker_1_name }}" width="64" height="64" style="border-radius:32px;vertical-align:middle"> {{/if}}{{ speaker_1_name }}{{#if speaker_1_regalia}}, {{ speaker_1_regalia }}{{/if}}{{#if speaker_1_position}}, {{ speaker_1_position }}{{/if}}</p>
{{#if speaker_2_name}}<p>{{#if speaker_2_photo}}<img src="{{ speaker_2_photo }}" alt="{{ speaker_2_name }}" width="64" height="64" style="border-radius:32px;vertical-align:middle"> {{/if}}{{ speaker_2_name }}{{#if speaker_2_regalia}}, {{ speaker_2_regalia }}{{/if}}{{#if speaker_2_position}}, {{ speaker_2_position }}{{/if}}</p>{{/if}}
{{#if speaker_3_name}}<p>{{#if speaker_3_photo}}<img src="{{ speaker_3_photo }}" alt="{{ speaker_3_name }}" width="64" height="64" style="border-radius:32px;vertical-align:middle"> {{/if}}{{ speaker_3_name }}{{#if speaker_3_regalia}}, {{ speaker_3_regalia }}{{/if}}{{#if speaker_3_position}}, {{ speaker_3_position }}{{/if}}</p>{{/if}}
{{/if}}
{{#if password}}<p>Данные для входа на сайт:<br>Логин: {{ user_email }}<br>Пароль: {{ password }}</p>{{/if}}
<p><a href="{{ event_url }}">Страница мероприятия</a></p>
HTML,
                'body_text' => <<<'TEXT'
Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!

Вы зарегистрированы на мероприятие «{{ event_title }}».
{{#if start_date}}Дата: {{ start_date }} {{ start_time }}{{/if}}
{{#if event_location}}Место: {{ event_location }}{{/if}}
{{#if password}}Логин: {{ user_email }}
Пароль: {{ password }}{{/if}}

Страница мероприятия: {{ event_url }}
TEXT,
            ],
            [
                'slug' => 'password-reset',
                'name' => 'Восстановление пароля',
                'subject' => 'Новый пароль для входа',
                'body_html' => <<<'HTML'
<p>Здравствуйте{{#if name}}, {{ name }}{{/if}}!</p>
<p>Для вашей учётной записи создан новый пароль.</p>
<p>Логин: {{ user_email }}<br>Пароль: {{ password }}</p>
HTML,
                'body_text' => <<<'TEXT'
Здравствуйте{{#if name}}, {{ name }}{{/if}}!

Для вашей учётной записи создан новый пароль.
Логин: {{ user_email }}
Пароль: {{ password }}
TEXT,
            ],
            [
                'slug' => 'api-registration',
                'name' => 'Регистрация через API',
                'subject' => 'Ваша учётная запись создана',
                'body_html' => <<<'HTML'
<p>Здравствуйте{{#if name}}, {{ name }}{{/if}}!</p>
<p>Для вас создана учётная запись.</p>
<p>Логин: {{ user_email }}<br>Пароль: {{ password }}</p>
HTML,
                'body_text' => <<<'TEXT'
Здравствуйте{{#if name}}, {{ name }}{{/if}}!

Для вас создана учётная запись.
Логин: {{ user_email }}
Пароль: {{ password }}
TEXT,
            ],
        ];
    }
}
