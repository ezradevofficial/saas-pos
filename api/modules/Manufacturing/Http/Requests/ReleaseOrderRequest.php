<?php

namespace Modules\Manufacturing\Http\Requests;

/** POST manufacturing/orders/{id}/release (MFG-11): `manufacturing.order.release`. */
class ReleaseOrderRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.order.release';
    }
}
