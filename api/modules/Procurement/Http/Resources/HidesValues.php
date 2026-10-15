<?php

namespace Modules\Procurement\Http\Resources;

use App\Core\Identity\Models\User;
use Illuminate\Http\Request;
use Modules\Procurement\Support\Places;

/**
 * RBAC-05 (stage 7B closing review, decision 27): prices, values and
 * landed costs are shown only to holders of `procurement.value.view`
 * where the document is (its branch or location); everyone else (a
 * storekeeper receiving goods) sees quantities, and money fields come back
 * null, in the API, exports (built from the same resources) and prints.
 * Answers are kept per request.
 */
trait HidesValues
{
    private bool $valuesVisible = true;

    public const VALUE_PERMISSION = 'procurement.value.view';

    public static function mayViewValues(Request $request, string $branchId, ?string $locationId = null): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $key = $branchId.'|'.($locationId ?? '');
        $cache = $request->attributes->get('procurement.values', []);

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = app(Places::class)->sees($user, self::VALUE_PERMISSION, $branchId, $locationId);
            $request->attributes->set('procurement.values', $cache);
        }

        return $cache[$key];
    }

    protected function seeValues(Request $request, string $branchId, ?string $locationId = null): void
    {
        $this->valuesVisible = self::mayViewValues($request, $branchId, $locationId);
    }

    /** @return array{amount_minor: string, currency: string}|null */
    protected function money(mixed $minor, ?string $currency): ?array
    {
        return $this->valuesVisible ? Refs::money($minor, $currency) : null;
    }
}
