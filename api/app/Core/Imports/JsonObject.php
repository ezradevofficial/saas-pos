<?php

namespace App\Core\Imports;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** A jsonb object column read as an array: an empty one is stored as `{}`, never `[]`. */
class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return $value === null ? [] : (array) json_decode((string) $value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $value = (array) $value;

        return $value === [] ? '{}' : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
