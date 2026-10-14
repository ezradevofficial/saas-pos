<?php

namespace Modules\Stores\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;

/**
 * STR-13, RBAC-05: values are shown only to holders of `stores.value.view`
 * at the store; everyone else gets null. Answers are kept per request.
 */
final class Values
{
    public const PERMISSION = 'stores.value.view';

    public const FIELDS = ['value', 'unit_cost', 'issued_value', 'returned_value', 'net_value', 'total_value'];

    public static function visible(Request $request, ?string $locationId): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $key = $locationId ?? '*';
        $cache = $request->attributes->get('stores.values', []);

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = app(ScopeResolver::class)->can($user, self::PERMISSION, $locationId === null ? null : Scope::location($locationId));
            $request->attributes->set('stores.values', $cache);
        }

        return $cache[$key];
    }

    /** Whether the user holds the permission anywhere (exports keep the value columns). */
    public static function anywhere(Request $request): bool
    {
        return self::visible($request, null);
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(int|string|null $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }
}
