<?php

namespace App\Core\Configuration;

/**
 * A small JSON-schema-like checker for configuration payloads (LAY-06).
 * A schema is an array:
 *
 *   ['type' => 'object', 'required' => ['columns'], 'additional' => false,
 *    'properties' => [
 *        'columns' => ['type' => 'array', 'max' => 50, 'unique' => 'id', 'items' => [
 *            'type' => 'object', 'required' => ['id'],
 *            'properties' => ['id' => ['type' => 'string', 'max' => 64], 'width' => ['type' => 'integer', 'min' => 1, 'max' => 12]],
 *        ]],
 *        'sort' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'nullable' => true],
 *    ]]
 *
 * Types: object, array, string, integer, number, boolean, any. Keywords:
 * nullable, required, properties, additional (false refuses keys not in
 * properties), items, min/max (string length, array size or number),
 * enum, pattern (a PCRE), unique (array of objects: a key whose values
 * must differ). Problems name the path (`columns.2.width`), a code and a
 * translated message.
 */
final class PayloadSchema
{
    /**
     * $path names where $value sits in a larger payload (problems are named from there).
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function check(mixed $value, array $schema, string $path = ''): array
    {
        $problems = [];
        self::walk($value, $schema, $path, $problems);

        return $problems;
    }

    /** @return array{path: string, code: string, message: string} */
    public static function problem(string $path, string $code, array $replace = []): array
    {
        return [
            'path' => $path,
            'code' => $code,
            'message' => __('config.problems.'.$code, ['path' => $path === '' ? __('config.problems.root') : $path, ...$replace]),
        ];
    }

    private static function walk(mixed $value, array $schema, string $path, array &$problems): void
    {
        if ($value === null) {
            if (! ($schema['nullable'] ?? false) && ($schema['type'] ?? 'any') !== 'any') {
                $problems[] = self::problem($path, 'not_null');
            }

            return;
        }

        $type = $schema['type'] ?? 'any';

        if (! self::isType($value, $type)) {
            $problems[] = self::problem($path, 'type', ['type' => $type]);

            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $problems[] = self::problem($path, 'enum', ['values' => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $schema['enum']))]);
        }

        match ($type) {
            'object' => self::object($value, $schema, $path, $problems),
            'array' => self::list($value, $schema, $path, $problems),
            'string' => self::string($value, $schema, $path, $problems),
            'integer', 'number' => self::number($value, $schema, $path, $problems),
            default => null,
        };
    }

    private static function object(array $value, array $schema, string $path, array &$problems): void
    {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $problems[] = self::problem(self::join($path, $key), 'required');
            }
        }

        $properties = $schema['properties'] ?? [];

        foreach ($value as $key => $child) {
            if (isset($properties[$key])) {
                self::walk($child, $properties[$key], self::join($path, (string) $key), $problems);
            } elseif (($schema['additional'] ?? true) === false) {
                $problems[] = self::problem(self::join($path, (string) $key), 'unknown');
            }
        }
    }

    private static function list(array $value, array $schema, string $path, array &$problems): void
    {
        if (isset($schema['min']) && count($value) < $schema['min']) {
            $problems[] = self::problem($path, 'min_items', ['min' => $schema['min']]);
        }

        if (isset($schema['max']) && count($value) > $schema['max']) {
            $problems[] = self::problem($path, 'max_items', ['max' => $schema['max']]);

            return;
        }

        $seen = [];

        foreach ($value as $index => $item) {
            if (isset($schema['items'])) {
                self::walk($item, $schema['items'], self::join($path, (string) $index), $problems);
            }

            if (isset($schema['unique']) && is_array($item) && isset($item[$schema['unique']]) && is_scalar($item[$schema['unique']])) {
                $id = (string) $item[$schema['unique']];

                if (isset($seen[$id])) {
                    $problems[] = self::problem(self::join(self::join($path, (string) $index), $schema['unique']), 'duplicate', ['value' => $id]);
                }

                $seen[$id] = true;
            }
        }
    }

    private static function string(string $value, array $schema, string $path, array &$problems): void
    {
        $length = mb_strlen($value);

        if (isset($schema['min']) && $length < $schema['min']) {
            $problems[] = self::problem($path, 'min_length', ['min' => $schema['min']]);
        }

        if (isset($schema['max']) && $length > $schema['max']) {
            $problems[] = self::problem($path, 'max_length', ['max' => $schema['max']]);
        }

        if (isset($schema['pattern']) && preg_match($schema['pattern'], $value) !== 1) {
            $problems[] = self::problem($path, 'pattern');
        }
    }

    private static function number(int|float $value, array $schema, string $path, array &$problems): void
    {
        if (isset($schema['min']) && $value < $schema['min']) {
            $problems[] = self::problem($path, 'min', ['min' => $schema['min']]);
        }

        if (isset($schema['max']) && $value > $schema['max']) {
            $problems[] = self::problem($path, 'max', ['max' => $schema['max']]);
        }
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            // JSON objects decode to associative arrays; an empty one may come as [].
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => true,
        };
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}
