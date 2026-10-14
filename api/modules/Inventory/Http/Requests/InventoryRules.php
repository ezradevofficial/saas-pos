<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Validation\Rule;

/**
 * Validation pieces shared by Inventory's Form Requests. Ids are checked
 * with `exists` under row-level security (another tenant's id does not
 * exist: 422); a place the user may not act at is refused (403).
 */
final class InventoryRules
{
    /** A decimal string with up to 14 digits and 6 decimals (INV-03); signed when asked. */
    public static function qty(bool $signed = false): string
    {
        return 'regex:/^'.($signed ? '-?' : '').'\d{1,14}(\.\d{1,6})?$/';
    }

    /** A unit cost: a decimal string in major units, up to 6 decimals, not negative. */
    public const UNIT_COST = 'regex:/^\d{1,14}(\.\d{1,6})?$/';

    /** @return array<string, mixed> one stock line (`$at` is the line's prefix, e.g. `lines.*.`) */
    public static function line(string $at, bool $signed = false, bool $receiving = false): array
    {
        return [
            "{$at}item_id" => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            "{$at}uom_id" => ['sometimes', 'nullable', 'uuid', Rule::exists('uoms', 'id')],
            "{$at}qty" => ['required', 'string', self::qty($signed)],
            "{$at}batch_id" => ['sometimes', 'nullable', 'uuid', Rule::exists('inventory_batches', 'id')],
            "{$at}serials" => ['sometimes', 'array', 'max:1000'],
            "{$at}serials.*" => ['required', 'string', 'max:100'],
            ...($receiving ? [
                "{$at}batch_number" => ['sometimes', 'nullable', 'string', 'max:60'],
                "{$at}expires_on" => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                // ADR 003: a typed unit cost is money: minor units per entered unit, with its currency.
                "{$at}unit_cost_minor" => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}$/'],
                "{$at}unit_cost_currency" => ['nullable', 'required_with:'.$at.'unit_cost_minor', 'string', 'regex:/^[A-Z]{3}$/'],
            ] : []),
        ];
    }

    public static function location(): array
    {
        return ['required', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at')];
    }

    /** 403 unless $user holds $permission at $locationId. */
    public static function at(User $user, string $permission, string $locationId): void
    {
        if (! app(ScopeResolver::class)->can($user, $permission, Scope::location($locationId))) {
            throw new ApiException(403, 'forbidden', __('inventory.errors.place_forbidden'));
        }
    }
}
