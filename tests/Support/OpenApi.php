<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Проверка данных по спецификации docs/sender-api.openapi.yaml: схемы запросов и ответов, поиск операции по адресу.
 * Нужна обеим сторонам контракта: провайдер (Sender) сверяет свои ответы, потребитель (сайт) сверяет свои запросы.
 */
final class OpenApi
{
    private static ?array $spec = null;

    public static function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(dirname(__DIR__, 2).'/docs/sender-api.openapi.yaml');
    }

    /**
     * Операция по методу и пути запроса (с префиксом /api/sender/v1). Null, если такой операции нет в контракте.
     *
     * @return array{path: string, method: string, operation: array<string, mixed>}|null
     */
    public static function operation(string $method, string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = '/'.ltrim((string) preg_replace('~^.*?/api/sender/v1~', '', $path), '/');

        foreach (self::spec()['paths'] as $template => $operations) {
            $regex = '~^'.preg_replace('~\\\{[^/]+\\\}~', '[^/]+', preg_quote($template, '~')).'$~';

            if (preg_match($regex, $path) === 1 && isset($operations[strtolower($method)])) {
                return ['path' => $template, 'method' => strtolower($method), 'operation' => $operations[strtolower($method)]];
            }
        }

        return null;
    }

    /**
     * Схема тела запроса операции (application/json) или null, если у операции нет тела.
     *
     * @return array<string, mixed>|null
     */
    public static function requestSchema(string $path, string $method): ?array
    {
        return self::spec()['paths'][$path][$method]['requestBody']['content']['application/json']['schema'] ?? null;
    }

    /**
     * Схема ответа операции. Бросает исключение, если такого кода в контракте нет.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(string $path, string $method, int $status, string $mime = 'application/json'): array
    {
        $response = self::spec()['paths'][$path][$method]['responses'][(string) $status] ?? null;

        if ($response === null) {
            throw new \RuntimeException("{$method} {$path}: ответ {$status} не описан в контракте");
        }

        while (isset($response['$ref'])) {
            $response = self::spec()['components']['responses'][basename($response['$ref'])];
        }

        return $response['content'][$mime]['schema'] ?? [];
    }

    /**
     * Расхождения значения со схемой: $ref, type (в том числе список типов), required, properties, enum, items,
     * minLength/maxLength, minItems/maxItems, pattern, format email.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema, string $path = '$'): array
    {
        $schema = self::resolve($schema);
        $errors = [];
        $types = (array) ($schema['type'] ?? []);

        if ($types !== []) {
            $actual = match (true) {
                $value === null => 'null',
                is_bool($value) => 'boolean',
                is_int($value) => 'integer',
                is_float($value) => 'number',
                is_string($value) => 'string',
                is_array($value) => array_is_list($value) ? 'array' : 'object',
                default => 'unknown',
            };

            // пустой массив PHP одинаково может быть и списком, и объектом
            $matches = in_array($actual, $types, true)
                || ($actual === 'integer' && in_array('number', $types, true))
                || ($value === [] && (in_array('object', $types, true) || in_array('array', $types, true)));

            if (! $matches) {
                return ["{$path}: ожидался тип ".implode('|', $types).", получен {$actual}"];
            }
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: значение ".json_encode($value, JSON_UNESCAPED_UNICODE).' не входит в enum';
        }

        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "{$path}: длиннее {$schema['maxLength']} символов";
            }

            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "{$path}: короче {$schema['minLength']} символов";
            }

            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~u', $value) !== 1) {
                $errors[] = "{$path}: не соответствует pattern {$schema['pattern']}";
            }

            if (($schema['format'] ?? null) === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "{$path}: не email";
            }
        }

        if (is_array($value) && ! array_is_list($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}: нет обязательного поля {$key}";
                }
            }

            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    $errors = [...$errors, ...self::errors($value[$key], $sub, "{$path}.{$key}")];
                }
            }
        }

        if (is_array($value) && array_is_list($value)) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: больше {$schema['maxItems']} элементов";
            }

            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "{$path}: меньше {$schema['minItems']} элементов";
            }

            foreach ($value as $index => $item) {
                if (isset($schema['items'])) {
                    $errors = [...$errors, ...self::errors($item, $schema['items'], "{$path}[{$index}]")];
                }
            }
        }

        return $errors;
    }

    /**
     * Схема с раскрытым $ref.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function resolve(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $resolved = self::spec();

            foreach (explode('/', ltrim($schema['$ref'], '#/')) as $part) {
                $resolved = $resolved[$part];
            }

            $schema = $resolved;
        }

        return $schema;
    }

    /**
     * Минимальный пример значения, который проходит схему: нужен, чтобы фейковый Sender отвечал по контракту.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function example(array $schema): mixed
    {
        $schema = self::resolve($schema);

        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }

        $type = collect((array) ($schema['type'] ?? 'object'))->first(fn ($t): bool => $t !== 'null') ?? 'null';

        return match ($type) {
            'object' => collect($schema['properties'] ?? [])->map(fn (array $sub) => self::example($sub))->all(),
            'array' => [self::example($schema['items'] ?? ['type' => 'string'])],
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'null' => null,
            default => match ($schema['format'] ?? null) {
                'email' => 'anna@example.com',
                'uuid' => '9f6c2a1e-0000-4000-8000-000000000001',
                'date-time' => '2026-10-14T10:00:00+03:00',
                default => 'example',
            },
        };
    }
}
