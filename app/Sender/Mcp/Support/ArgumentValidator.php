<?php

namespace App\Sender\Mcp\Support;

/**
 * Проверка аргументов инструмента по его JSON Schema: обязательные поля, типы, enum, лишние поля, длина строк и границы чисел.
 * Полный JSON Schema не нужен: схемы инструментов используют только эти ключевые слова.
 */
class ArgumentValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> ошибки; пусто — аргументы подходят
     */
    public function validate(mixed $value, array $schema, string $path = 'arguments'): array
    {
        $errors = [];
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! $this->matchesAny($value, $types)) {
            return ["{$path}: ожидается ".implode(' или ', $types).', получено '.$this->typeOf($value)];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: допустимо ".implode(', ', array_map(fn ($v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE), $schema['enum']));
        }

        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "{$path}: не длиннее {$schema['maxLength']} символов";
            }

            if (isset($schema['pattern']) && ! preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value)) {
                $errors[] = "{$path}: не подходит под шаблон {$schema['pattern']}";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: не меньше {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: не больше {$schema['maximum']}";
            }
        }

        if (is_array($value) && array_is_list($value) && $value !== [] && in_array('array', $types, true)) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: не больше {$schema['maxItems']} элементов";
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        }

        if (is_array($value) && in_array('object', $types, true)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$path}.{$required}: обязательное поле";
                }
            }

            foreach ($value as $key => $item) {
                if (isset($properties[$key])) {
                    array_push($errors, ...$this->validate($item, $properties[$key], "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key}: неизвестное поле";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $types
     */
    private function matchesAny(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                // пустой JSON-объект {} декодируется в пустой массив
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => true,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            is_array($value) && array_is_list($value) && $value !== [] => 'array',
            default => 'object',
        };
    }
}
