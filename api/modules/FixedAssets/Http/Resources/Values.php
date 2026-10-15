<?php

namespace Modules\FixedAssets\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;

/**
 * RBAC-05, FA-26: costs and book values show only to holders of
 * `fixed_assets.value.view` at the asset's location (or the company for
 * company records); everyone else gets null. Answers are kept per request.
 */
final class Values
{
    public const PERMISSION = 'fixed_assets.value.view';

    public static function at(Request $request, ?string $locationId): bool
    {
        return self::check($request, $locationId === null ? null : Scope::location($locationId), 'l:'.($locationId ?? '*'));
    }

    public static function atCompany(Request $request, string $companyId): bool
    {
        return self::check($request, Scope::company($companyId), 'c:'.$companyId);
    }

    /** Whether the user holds the permission anywhere (exports keep the value columns). */
    public static function anywhere(Request $request): bool
    {
        return self::check($request, null, '*');
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(bool $visible, int|string|null $minor, ?string $currency): ?array
    {
        return ! $visible || $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }

    private static function check(Request $request, ?Scope $scope, string $key): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $cache = $request->attributes->get('fixed_assets.values', []);

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = app(ScopeResolver::class)->can($user, self::PERMISSION, $scope);
            $request->attributes->set('fixed_assets.values', $cache);
        }

        return $cache[$key];
    }
}
