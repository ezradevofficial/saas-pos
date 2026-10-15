<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Validation\Validator;

/** PATCH procurement/receipts/{id} (PRC-14): a draft, `procurement.receipt.create` where it is (and at a new location); the fields of StoreReceiptRequest, each optional. */
class UpdateReceiptRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreReceiptRequest::fields(required: false);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->filled('location_id')) {
                ProcurementRules::atLocation($this->user(), 'procurement.receipt.create', (string) $this->input('location_id'));
            }
        }];
    }
}
