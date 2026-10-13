<?php

namespace Modules\Inventory\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\Batch;

/** Small references to records Inventory's resources name (as entered, single language). */
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

    /** @return array{id: string, batch_number: string, expires_on: ?string}|null */
    public static function batch(?Batch $batch): ?array
    {
        return $batch === null ? null : ['id' => $batch->id, 'batch_number' => $batch->batch_number, 'expires_on' => $batch->expires_on?->toDateString()];
    }

    public static function at(mixed $value): ?string
    {
        return $value === null ? null : $value->toIso8601String();
    }
}
