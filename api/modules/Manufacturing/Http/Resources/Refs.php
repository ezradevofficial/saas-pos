<?php

namespace Modules\Manufacturing\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use Illuminate\Database\Eloquent\Model;

/** Small references to records Manufacturing's resources name (as entered, single language). */
final class Refs
{
    /** @return array{id: string, code: string, name: string, type: string}|null */
    public static function item(?Item $item): ?array
    {
        return $item === null ? null : ['id' => $item->id, 'code' => (string) $item->code, 'name' => $item->name, 'type' => $item->type];
    }

    /** @return array{id: string, name: string}|null a company, branch or location */
    public static function named(?Model $record): ?array
    {
        return $record === null ? null : ['id' => (string) $record->getKey(), 'name' => (string) $record->getAttribute('name')];
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
