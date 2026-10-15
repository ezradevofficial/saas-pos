<?php

namespace Modules\CRM\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** Small references the CRM resources name (business names as entered, single language) and money and date shapes (ADR 003). */
final class Refs
{
    /** @return array{id: string, name: string}|null a company, branch, party, team, pipeline or option */
    public static function named(?Model $model): ?array
    {
        return $model === null ? null : ['id' => (string) $model->getKey(), 'name' => (string) $model->getAttribute('name')];
    }

    /** @return array{id: string, name: string}|null */
    public static function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => (string) $user->name];
    }

    /** @return array{id: string, code: string, name: string}|null */
    public static function item(?Item $item): ?array
    {
        return $item === null ? null : ['id' => $item->id, 'code' => (string) $item->code, 'name' => $item->name];
    }

    /** @return array{id: string, code: string}|null */
    public static function uom(?Uom $uom): ?array
    {
        return $uom === null ? null : ['id' => $uom->id, 'code' => (string) $uom->code, 'name' => $uom->name];
    }

    /** @return array{id: string, code: string, name: string}|null */
    public static function taxCode(?TaxCode $code): ?array
    {
        return $code === null ? null : ['id' => $code->id, 'code' => (string) $code->code, 'name' => (string) $code->name];
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(mixed $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }

    public static function at(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->toDateString();
    }

    /** A decimal string without trailing zeros. */
    public static function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
