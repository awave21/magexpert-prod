<?php

namespace App\Sender\Mcp;

/**
 * Готовые сценарии (prompts MCP): пользователь выбирает их в клиенте, ассистент получает пошаговую инструкцию.
 */
class Prompts
{
    /**
     * @return list<array{name: string, title: string, description: string, arguments: list<array{name: string, description: string, required: bool}>}>
     */
    public function list(): array
    {
        return [
            [
                'name' => 'event_announcement',
                'title' => 'Анонс мероприятия',
                'description' => 'Создать письмо-анонс мероприятия в фирменном стиле, проверить его и отправить тест себе.',
                'arguments' => [
                    ['name' => 'title', 'description' => 'Название мероприятия', 'required' => true],
                    ['name' => 'date', 'description' => 'Дата и время, например «15 ноября 2026, 10:00 МСК»', 'required' => true],
                    ['name' => 'link', 'description' => 'Ссылка на страницу мероприятия (https://…)', 'required' => true],
                    ['name' => 'audience', 'description' => 'Для кого: специальность, уровень, например «кардиологи и терапевты»', 'required' => false],
                ],
            ],
            [
                'name' => 'improve_letter',
                'title' => 'Улучшить письмо',
                'description' => 'Разобрать существующее письмо и сделать улучшенную версию (по умолчанию — копией, не трогая оригинал).',
                'arguments' => [
                    ['name' => 'template', 'description' => 'ID или идентификатор (slug) письма', 'required' => true],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>}
     *
     * @throws JsonRpcException
     */
    public function get(string $name, array $arguments): array
    {
        $prompt = collect($this->list())->firstWhere('name', $name);

        if ($prompt === null) {
            throw new JsonRpcException("Сценарий «{$name}» не найден", JsonRpcException::INVALID_PARAMS);
        }

        $missing = collect($prompt['arguments'])
            ->filter(fn (array $arg): bool => $arg['required'] && blank($arguments[$arg['name']] ?? null))
            ->pluck('name')->all();

        if ($missing !== []) {
            throw new JsonRpcException('Не заполнены аргументы: '.implode(', ', $missing), JsonRpcException::INVALID_PARAMS);
        }

        $arg = fn (string $key): string => trim((string) ($arguments[$key] ?? ''));

        $text = match ($name) {
            'event_announcement' => <<<TXT
                Подготовь в Sender письмо-анонс мероприятия.

                Мероприятие: {$arg('title')}
                Дата и время: {$arg('date')}
                Ссылка: {$arg('link')}
                Аудитория: {$this->orDefault($arg('audience'), 'врачи и медицинские специалисты')}

                Порядок работы:
                1. Вызови get_brand и get_block_schema (или прочитай ресурсы sender://brand, sender://blocks/schema и sender://guide/letters).
                2. Вызови list_variables, чтобы использовать имя получателя ({{ first_name }}) и ссылку отписки ({{ unsubscribe_url }}).
                3. Напиши письмо по руководству: заголовок, приветствие по имени, 1–2 абзаца о пользе для аудитории, карточка с датой и форматом, одна кнопка на ссылку, подвал с отпиской. Тема до 60 символов, прехедер 40–90 символов.
                4. Создай его через create_template из блоков (blocks) в папке «Мероприятия», с цветами и шрифтом из get_brand.
                5. Запусти check_template (kind: campaign) и исправь все ошибки через update_template.
                6. Покажи результат через render_template и отправь send_test себе.
                7. В ответе дай название, тему, прехедер, ссылку edit_url на письмо и итог проверки.
                TXT,
            'improve_letter' => <<<TXT
                Улучши письмо «{$arg('template')}» в Sender, не испортив оригинал.

                1. Прочитай его через get_template, а также get_brand и ресурс sender://guide/letters.
                2. Запусти check_template и render_template, чтобы увидеть замечания и итоговый вид.
                3. Предложи пользователю список улучшений: тема и прехедер, структура, ясность призыва к действию, длина, доступность (alt, контраст), ссылки.
                4. Сделай улучшенную версию КОПИЕЙ через create_template (название с пометкой «— улучшенная», в той же папке; блочное письмо — снова из blocks).
                   Перезаписывай оригинал через update_template только если пользователь прямо попросил, и тогда передай expected_updated_at из get_template.
                5. Проверь новую версию через check_template и отправь send_test себе.
                6. Кратко опиши, что изменено и почему, и дай ссылку edit_url.
                TXT,
        };

        return [
            'description' => $prompt['description'],
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    private function orDefault(string $value, string $default): string
    {
        return $value !== '' ? $value : $default;
    }
}
