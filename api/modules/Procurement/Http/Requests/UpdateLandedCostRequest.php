<?php

namespace Modules\Procurement\Http\Requests;

/** PATCH procurement/landed-costs/{id} (PRC-17): a draft, `procurement.landed_cost.create` where it is; the fields of StoreLandedCostRequest, each optional. */
class UpdateLandedCostRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreLandedCostRequest::fields(required: false);
    }
}
