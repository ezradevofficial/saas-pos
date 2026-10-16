<?php

namespace Modules\Manufacturing\Http\Requests;

/** POST manufacturing/orders/{id}/close (MFG-14): `manufacturing.order.close`; `{note?}`. */
class CloseOrderRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.order.close';
    }

    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }
}
