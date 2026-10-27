<?php

namespace Modules\Salon\Appointments;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonInterface;
use Modules\Salon\Models\Service;

/**
 * SAL-07, SAL-16: the price of a service, from master data (MD-03): the item's price in the company's default price
 * list in its base currency on the day. Staff never type it; the till rings the same price from the same list.
 */
class Pricing
{
    public function __construct(private readonly PriceResolver $prices) {}

    /** The price of one unit in minor units, or null when the item has no list price (the service cannot be booked). */
    public function unit(Service $service, Company $company, CarbonInterface $at): ?int
    {
        $item = Item::query()->find($service->item_id);

        if ($item === null || $item->isArchived()) {
            return null;
        }

        $price = $this->prices->priceFor($item, $item->base_uom_id, $company, $at, '1', $company->base_currency);

        return $price === null ? null : (int) $price->money->minor();
    }
}
