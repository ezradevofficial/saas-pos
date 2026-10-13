<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Bank\StatementParser;

/** POST accounting/bank-accounts/{acc_bank_account}/statements: a CSV or M-Pesa statement file (ACC-13). */
class ImportStatementRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.bank.reconcile';
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('accounting.statement_max_kb'), 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
            'format' => ['required', 'string', Rule::in(['csv', 'mpesa'])],
            'mapping' => ['sometimes', 'array'],
            'mapping.date' => ['nullable', 'string', 'max:100'],
            'mapping.description' => ['nullable', 'string', 'max:100'],
            'mapping.reference' => ['nullable', 'string', 'max:100'],
            'mapping.amount' => ['nullable', 'string', 'max:100'],
            'mapping.money_in' => ['nullable', 'string', 'max:100'],
            'mapping.money_out' => ['nullable', 'string', 'max:100'],
            'mapping.balance' => ['nullable', 'string', 'max:100'],
            'mapping.date_format' => ['nullable', 'string', Rule::in(StatementParser::DATE_FORMATS)],
        ];
    }
}
