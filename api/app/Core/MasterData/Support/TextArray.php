<?php

namespace App\Core\MasterData\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A PostgreSQL `text[]` column as a PHP list of strings. Elements are
 * always written quoted, so commas, spaces, quotes and backslashes survive.
 */
class TextArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return $value === null ? [] : self::parse((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::format(array_values((array) ($value ?? [])));
    }

    /** @param list<string> $values */
    public static function format(array $values): string
    {
        return '{'.implode(',', array_map(
            fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"',
            $values,
        )).'}';
    }

    /** @return list<string> */
    public static function parse(string $literal): array
    {
        $inner = substr(trim($literal), 1, -1);
        $values = [];
        $length = strlen($inner);
        $i = 0;

        while ($i < $length) {
            if ($inner[$i] === '"') {
                $value = '';
                $i++;

                while ($i < $length && $inner[$i] !== '"') {
                    if ($inner[$i] === '\\') {
                        $i++;
                    }

                    $value .= $inner[$i];
                    $i++;
                }

                $values[] = $value;
                $i += 2; // closing quote and comma

                continue;
            }

            $end = strpos($inner, ',', $i);
            $end = $end === false ? $length : $end;
            $values[] = substr($inner, $i, $end - $i);
            $i = $end + 1;
        }

        return $values;
    }
}
