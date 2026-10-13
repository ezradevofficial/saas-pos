<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\Payment;

/** POST companies/{company}/accounting/payments: a receipt or payment, posted, with allocations (ACC-10). */
class StorePaymentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.payment.create';
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(Payment::KINDS)],
            'party_id' => ['required', 'uuid', Rule::exists('parties', 'id')],
            'bank_account_id' => ['required', 'uuid', $this->ofCompany('acc_bank_accounts')],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?\z/'],
            'reference' => ['nullable', 'string', 'max:80'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['sometimes', 'array', 'max:100'],
            'allocations.*.document_id' => ['required', 'uuid', $this->ofCompany('acc_documents')],
            'allocations.*.amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
        ];
    }
}
