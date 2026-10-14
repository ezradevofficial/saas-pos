<?php

namespace Modules\Procurement\Http\Requests;

/** PATCH procurement/rfqs/{id} (PRC-03): a draft, `procurement.rfq.create` where it is; title, note, deadline, lines and suppliers, each optional. */
class UpdateRfqRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreRfqRequest::fields();
    }
}
