<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;

/** POST van-sales/loads/{id}/release (VAN-07): `van_sales.load.release` at the warehouse the stock leaves. */
class ReleaseLoadRequest extends BoundRequest
{
    protected function target(): ?Scope
    {
        return Scope::location($this->route('vs_load')->from_location_id);
    }
}
