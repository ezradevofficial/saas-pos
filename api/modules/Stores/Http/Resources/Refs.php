<?php

namespace Modules\Stores\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use Illuminate\Database\Eloquent\Model;

/** Small references to records Stores' resources name (as entered, single language). */
final class Refs
{
    /** @return array{id: string, code: string, name: string}|null */
    public static function item(?Item $item): ?array
    {
        return $item === null ? null : ['id' => $item->id, 'code' => (string) $item->code, 'name' => $item->name];
    }

    /** @return array{id: string, name: string}|null a company, branch, location or dimension */
    public static function named(?Model $record): ?array
    {
        return $record === null ? null : ['id' => (string) $record->getKey(), 'name' => (string) $record->getAttribute('name')];
    }

    /** @return array{id: string, code: string, name: string}|null a department or cost centre */
    public static function dimension(?Model $record): ?array
    {
        return $record === null ? null : ['id' => (string) $record->getKey(), 'code' => (string) $record->getAttribute('code'), 'name' => (string) $record->getAttribute('name')];
    }

    /** @return array{id: string, code: string, name: string}|null */
    public static function uom(?Uom $uom): ?array
    {
        return $uom === null ? null : ['id' => $uom->id, 'code' => (string) $uom->code, 'name' => $uom->name];
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
