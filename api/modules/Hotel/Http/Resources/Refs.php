<?php

namespace Modules\Hotel\Http\Resources;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Small references to records Hotel's resources name (as entered, single language). */
final class Refs
{
    /** @return array{id: string, name: string}|null a company or branch */
    public static function place(?Model $place): ?array
    {
        return $place === null ? null : ['id' => (string) $place->getKey(), 'name' => (string) $place->getAttribute('name')];
    }

    /** @return array{id: string, name: string}|null */
    public static function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    public static function at(mixed $value): ?string
    {
        return $value === null ? null : $value->toIso8601String();
    }

    public static function day(mixed $value): ?string
    {
        return $value === null ? null : $value->toDateString();
    }
}
