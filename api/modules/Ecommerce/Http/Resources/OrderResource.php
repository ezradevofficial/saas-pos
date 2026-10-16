<?php

namespace Modules\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** ECOM-22: an order as a row of the orders list (and its export). */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Present::orderSummary($this->resource);
    }
}
