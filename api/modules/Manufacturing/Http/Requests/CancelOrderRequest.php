<?php

namespace Modules\Manufacturing\Http\Requests;

/** POST manufacturing/orders/{id}/cancel (MFG-14): `manufacturing.order.cancel`; `{reason}`. */
class CancelOrderRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.order.cancel';
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
