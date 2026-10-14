<?php

namespace Modules\Procurement\Http\Requests;

/** PATCH procurement/tenders/{id} (PRC-06): a draft, `procurement.tender.create` where it is; the fields of StoreTenderRequest, each optional. */
class UpdateTenderRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreTenderRequest::fields();
    }
}
