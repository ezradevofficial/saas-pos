<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use Illuminate\Validation\Validator;
use Modules\Procurement\CustomFields\OrderEntity;

/** PATCH procurement/orders/{id} (PRC-13): a draft, `procurement.po.create` where it is; the fields of StoreOrderRequest, each optional. */
class UpdateOrderRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreOrderRequest::fields(required: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, OrderEntity::KEY, $this->input('custom'), $this->document(), $this->user())];
    }
}
