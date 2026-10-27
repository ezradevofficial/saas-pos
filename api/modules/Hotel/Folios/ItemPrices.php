<?php

namespace Modules\Hotel\Folios;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Prices\ResolvedPrice;
use App\Core\Tenancy\Models\Company;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Support\Properties;

/**
 * HOT-21: the price of an extra on a folio, from master data: the item's price in the company's default price list in the folio's currency, on
 * the property's business date (MD-03). Staff do not type it: only a holder of `hotel.rate.override` may charge another price, with a reason
 * (audited). No list price means the price has to be typed by such a holder.
 */
class ItemPrices
{
    public function __construct(private readonly PriceResolver $prices, private readonly Properties $properties) {}

    public function for(Folio $folio, Item $item, string $qty = '1'): ?ResolvedPrice
    {
        return $this->prices->priceFor($item, $item->base_uom_id, Company::query()->findOrFail($folio->company_id), $this->properties->businessDate($folio->branch_id), $qty, $folio->currency);
    }
}
