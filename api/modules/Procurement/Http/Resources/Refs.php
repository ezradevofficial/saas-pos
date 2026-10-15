<?php

namespace Modules\Procurement\Http\Resources;

use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use Illuminate\Database\Eloquent\Model;

/** Small references Procurement's resources name (business names as entered, single language) and money and quantity shapes (ADR 003). */
final class Refs
{
    /** @return array{id: string, code: string, name: string}|null */
    public static function item(?Item $item): ?array
    {
        return $item === null ? null : ['id' => $item->id, 'code' => (string) $item->code, 'name' => $item->name, 'type' => $item->type];
    }

    /** @return array{id: string, name: string}|null a company, branch, location, party, department or dimension */
    public static function named(?Model $model): ?array
    {
        return $model === null ? null : ['id' => (string) $model->getKey(), 'name' => (string) $model->getAttribute('name')];
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

    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(mixed $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }

    public static function fx(?FxSnapshot $fx): ?array
    {
        return $fx?->jsonSerialize();
    }

    /** A decimal string without trailing zeros. */
    public static function qty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public static function at(mixed $value): ?string
    {
        return $value === null ? null : $value->toIso8601String();
    }

    public static function date(mixed $value): ?string
    {
        return $value === null ? null : $value->toDateString();
    }
}
