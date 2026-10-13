<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/documents/{acc_document}/allocate: a credit note against invoices (ACC-10). */
class AllocateDocumentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.payment.create';
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.document_id' => ['required', 'uuid', $this->ofCompany('acc_documents')],
            'allocations.*.amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
        ];
    }
}
