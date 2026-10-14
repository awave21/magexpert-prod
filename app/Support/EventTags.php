<?php

namespace App\Support;

/**
 * Правила тегов мероприятий. Совпадают с контрактом Sender (docs/sender-api.openapi.yaml, схема Tag):
 * Sender отклонит контакт, если тег им не соответствует.
 */
final class EventTags
{
    /** Буквы любого алфавита, цифры, пробел и _ . : , - ( ) / +; начинается с буквы или цифры, до 120 символов. */
    public const PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} _.:,\-()\/+]{0,119}$/u';

    public const MAX_LENGTH = 120;

    /**
     * Нижний регистр, пробелы схлопнуты, без пустых и повторов.
     *
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    public static function normalize(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            if (is_string($tag) && ($tag = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $tag)))) !== '') {
                $clean[$tag] = true;
            }
        }

        return array_map('strval', array_keys($clean));
    }
}
