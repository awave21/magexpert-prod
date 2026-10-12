<?php

namespace App\Sender\Mcp;

use App\Sender\Mcp\Support\BlockSchema;
use App\Sender\Mcp\Support\Brand;

/**
 * Ресурсы MCP: справочные данные, которые клиент может прочитать и приложить к разговору.
 */
class Resources
{
    public const BRAND = 'sender://brand';

    public const BLOCKS_SCHEMA = 'sender://blocks/schema';

    public const GUIDE = 'sender://guide/letters';

    public function __construct(private readonly Brand $brand) {}

    /**
     * @return list<array{uri: string, name: string, title: string, description: string, mimeType: string}>
     */
    public function list(): array
    {
        return [
            [
                'uri' => self::BRAND,
                'name' => 'brand',
                'title' => 'Фирменный стиль организации',
                'description' => 'Название, отправитель, цвета, шрифты и логотип для писем (то же, что get_brand).',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => self::BLOCKS_SCHEMA,
                'name' => 'blocks-schema',
                'title' => 'Блоки визуального редактора',
                'description' => 'Типы блоков, поля, допустимые значения и пример письма (то же, что get_block_schema).',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => self::GUIDE,
                'name' => 'letters-guide',
                'title' => 'Как писать письма',
                'description' => 'Короткое руководство: тон, структура, длина, призыв к действию, доступность и цвета для тёмной темы.',
                'mimeType' => 'text/markdown',
            ],
        ];
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     *
     * @throws JsonRpcException
     */
    public function read(string $uri, McpContext $ctx): array
    {
        return match ($uri) {
            self::BRAND => ['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->json($this->brand->for($ctx->organization))],
            self::BLOCKS_SCHEMA => ['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->json(BlockSchema::describe())],
            self::GUIDE => ['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => self::guide()],
            default => throw new JsonRpcException('Ресурс не найден', JsonRpcException::RESOURCE_NOT_FOUND, ['uri' => $uri]),
        };
    }

    public static function guide(): string
    {
        return <<<'MD'
# Как писать письма в Sender

Время читателя дорогое, поэтому письмо должно за несколько секунд отвечать на вопрос «что мне с этого и что сделать».

## Тон
- Обращение на «вы», уважительно и по делу, без панибратства и рекламных восклицаний.
- Профессиональная лексика аудитории уместна, канцелярит — нет. Вместо «осуществить регистрацию» — «зарегистрироваться».
- Без обещаний, которые нельзя проверить, и без утверждений без источника.
- Аудиторию, тон и ограничения организации (запретные слова, разрешённые сайты для ссылок) уточняйте у пользователя.

## Структура
1. Логотип (необязательно) и заголовок: суть в 4–8 словах.
2. Приветствие с именем: `Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!`
3. Один-два коротких абзаца: что, когда, для кого и почему стоит прийти.
4. Карточка с деталями (дата, время, формат, место, спикеры, если есть) — блоки text с фоном `#F4F6FA` и `inset: true`, каждая строка с условием `cond`.
5. Одна главная кнопка (CTA). Второстепенные ссылки — текстом.
6. Подвал: почему пришло письмо, реквизиты организации и ссылка отписки `{{ unsubscribe_url }}` (в рассылках обязательно).

## Длина
- Тема — до 60 символов, главное в первых 35. Без заглавных букв целиком и без «!!!».
- Прехедер — 40–90 символов, дополняет тему, а не повторяет её.
- Текст — 50–150 слов. Если нужно больше, дайте ссылку на страницу.

## Призыв к действию
- Глагол и результат: «Зарегистрироваться», «Смотреть запись», «Выбрать дату». Не «Нажмите здесь».
- Одна кнопка на экран; ссылка в кнопке — https или переменная `{{ event_url }}`.

## Доступность
- У каждой картинки осмысленный `alt`; важный текст не прячьте в картинку.
- Размер основного текста не меньше 15–16 px, межстрочный интервал 1.5–1.6.
- Контраст текста к фону не ниже 4.5:1; ссылки подчёркнуты.
- Письмо должно читаться и с отключёнными картинками.

## Тёмная тема и цвета
- Почтовые клиенты в тёмной теме инвертируют цвета: избегайте чисто чёрного `#000000` на чисто белом — берите `#1C1F27` на `#FFFFFF`.
- Цвет кнопки — насыщенный средний (`#4C68EB`), белый текст на нём читается в обеих темах.
- Не делайте текст картинкой и не полагайтесь только на цвет фона блока: он может поменяться.
- Логотип — PNG с прозрачным фоном и запасом по краям или вариант, который читается на тёмном фоне.

## Перед отправкой
1. `check_template` — исправьте все error и по возможности warning.
2. `render_template` — посмотрите, как подставились переменные.
3. `send_test` — тест себе на почту, проверьте на телефоне.
MD;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
