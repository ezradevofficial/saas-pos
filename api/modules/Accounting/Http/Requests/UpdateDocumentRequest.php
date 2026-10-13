<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** PATCH accounting/documents/{acc_document}: a draft (ACC-09). */
class UpdateDocumentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.invoice.create';
    }

    public function rules(): array
    {
        $req = 'sometimes';

        return [
            'credits_document_id' => ['sometimes', 'nullable', 'uuid', $this->ofCompany('acc_documents')],
            'party_id' => [$req, 'uuid', Rule::exists('parties', 'id')],
            'branch_id' => ['nullable', 'uuid', $this->ofCompany('branches')],
            'issue_date' => [$req, 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3', $this->activeCurrency()],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?\z/'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'supplier_reference' => ['nullable', 'string', 'max:80'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'lines' => [$req, 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')],
            'lines.*.account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,4})?\z/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'lines.*.tax_code_id' => ['nullable', 'uuid', $this->ofCompany('tax_codes')],
            'lines.*.cost_centre_id' => ['nullable', 'uuid', $this->ofCompany('cost_centres')],
            'lines.*.department_id' => ['nullable', 'uuid', $this->ofCompany('departments')],
            'lines.*.project_id' => ['nullable', 'uuid', $this->ofCompany('projects')],
        ];
    }
}
