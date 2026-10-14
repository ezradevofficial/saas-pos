<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use Illuminate\Validation\Validator;
use Modules\Procurement\CustomFields\RequisitionEntity;

/** PATCH procurement/requisitions/{id} (PRC-02): a draft, `procurement.requisition.create` where it is; the fields of StoreRequisitionRequest, each optional. */
class UpdateRequisitionRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreRequisitionRequest::fields(required: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, RequisitionEntity::KEY, $this->input('custom'), $this->document(), $this->user())];
    }
}
