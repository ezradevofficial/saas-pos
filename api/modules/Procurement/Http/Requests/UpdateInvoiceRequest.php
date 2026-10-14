<?php

namespace Modules\Procurement\Http\Requests;

/** PATCH procurement/invoices/{id} (PRC-15): an invoice not yet approved, `procurement.invoice.create` where it is; matched again on save. */
class UpdateInvoiceRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return StoreInvoiceRequest::fields(required: false);
    }
}
