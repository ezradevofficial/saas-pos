<?php

namespace Modules\Pharmacy\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use Illuminate\Database\Eloquent\Model;

/** Small references to records Pharmacy's resources name (as entered, single language). */
final class Refs
{
    /** @return array{id: string, code: string, name: string}|null */
    public static function item(?Item $item): ?array
    {
        return $item === null ? null : ['id' => $item->id, 'code' => (string) $item->code, 'name' => $item->name];
    }

    /** @return array{id: string, name: string}|null a company, branch or location */
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
}
