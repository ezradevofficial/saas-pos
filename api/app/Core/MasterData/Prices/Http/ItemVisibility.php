<?php

namespace App\Core\MasterData\Prices\Http;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Taxes\PriceList;
use Illuminate\Http\Request;

/**
 * Whether the requesting user may see the items a price list prices
 * (RBAC-04, TEN-08): `shared` items with `core.item.view` anywhere in the
 * tenant, the list's `company` items with it at, above or beneath that
 * company. Prices are readable with `core.price.view` alone (a till reads
 * them); the item's code and name are not. Cached on the request per list.
 */
final class ItemVisibility
{
    /** @return array{shared: bool, company: bool} */
    public static function for(Request $request, string $priceListId): array
    {
        $key = "prices.item_visibility.{$priceListId}";

        if (! $request->attributes->has($key)) {
            $user = $request->user();
            $reach = app(CompanyReach::class);
            $companyId = PriceList::query()->whereKey($priceListId)->value('company_id');

            $request->attributes->set($key, $user === null || $companyId === null
                ? ['shared' => false, 'company' => false]
                : [
                    'shared' => $reach->anywhere($user, ['core.item.view']),
                    'company' => $reach->reachesRecord($user, $companyId, ['core.item.view']),
                ]);
        }

        return $request->attributes->get($key);
    }
}
