<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use Illuminate\Validation\Validator;
use Modules\Manufacturing\CustomFields\OrderEntity;

/**
 * PUT manufacturing/orders/{id} (MFG-10): not found unless the user sees
 * the order; `manufacturing.order.create` at its location (403); planned
 * orders only (409); `{qty?, uom_id?, planned_start?, planned_end?, note?,
 * custom?}`.
 */
class UpdateOrderRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.order.create';
    }

    public function rules(): array
    {
        return StoreOrderRequest::fields(required: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, OrderEntity::KEY, $this->input('custom'), $this->order(), $this->user())];
    }
}
