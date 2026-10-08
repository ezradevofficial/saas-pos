<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** TEN-06: archive or restore a price list (`core.price_list.edit`). No body. */
class PriceListActionRequest extends PriceListRequest
{
    protected bool $edits = true;
}
