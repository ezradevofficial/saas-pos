<?php

namespace Modules\Inventory\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;

/**
 * RBAC-05, INV-19: costs and values are shown only to holders of
 * `inventory.valuation.view` where the row is (its location, else its
 * company); everyone else gets null. Answers are kept per request.
 */
final class Values
{
    public const PERMISSION = 'inventory.valuation.view';

    public const FIELDS = ['value', 'unit_cost', 'cost_variance', 'value_in', 'value_out', 'gain', 'loss', 'variance_value', 'costs'];

    public static function visible(Request $request, ?string $locationId, ?string $companyId = null): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $key = 'inventory.values.'.($locationId ?? 'c:'.$companyId);
        $cache = $request->attributes->get('inventory.values', []);

        if (! array_key_exists($key, $cache)) {
            $scope = $locationId !== null ? Scope::location($locationId) : ($companyId !== null ? Scope::company($companyId) : null);
            $cache[$key] = app(ScopeResolver::class)->can($user, self::PERMISSION, $scope);
            $request->attributes->set('inventory.values', $cache);
        }

        return $cache[$key];
    }

    /** Whether the user holds the permission anywhere (exports keep the value columns). */
    public static function anywhere(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && app(ScopeResolver::class)->can($user, self::PERMISSION);
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(int|string|null $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }
}
