<?php

namespace Modules\Manufacturing\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;
use Modules\Manufacturing\Support\Places;

/**
 * MFG-21, RBAC-05: costs and values are shown only to holders of
 * `manufacturing.cost.view` at the place (an order's location; a BOM's
 * company, held anywhere in it); everyone else gets null. Answers are kept
 * per request.
 */
final class Costs
{
    public const PERMISSION = 'manufacturing.cost.view';

    /** The cost columns of Manufacturing's lists, left out of exports for a reader who holds the permission nowhere. */
    public const FIELDS = [
        'material_cost', 'labour_cost', 'overhead_cost', 'relieved', 'variance', 'actual_value', 'standard_value', 'value_variance',
        'value', 'unit_cost', 'batch_cost', 'price', 'cost_share_percent',
    ];

    public static function atLocation(Request $request, ?string $locationId): bool
    {
        return self::memo($request, 'l:'.($locationId ?? '*'), fn (User $user) => app(ScopeResolver::class)->can($user, self::PERMISSION, $locationId === null ? null : Scope::location($locationId)));
    }

    public static function inCompany(Request $request, string $companyId): bool
    {
        return self::memo($request, 'c:'.$companyId, fn (User $user) => app(Places::class)->inCompany($user, self::PERMISSION, $companyId));
    }

    /** Whether the user holds the permission anywhere (exports keep the cost columns). */
    public static function anywhere(Request $request): bool
    {
        return self::atLocation($request, null);
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(int|string|null $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }

    private static function memo(Request $request, string $key, \Closure $check): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $cache = $request->attributes->get('manufacturing.costs', []);

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $check($user);
            $request->attributes->set('manufacturing.costs', $cache);
        }

        return $cache[$key];
    }
}
