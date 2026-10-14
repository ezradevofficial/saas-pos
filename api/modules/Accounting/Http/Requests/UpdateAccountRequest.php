<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\Account;

/** PATCH accounting/accounts/{acc_account}: an account's fields; an account with postings keeps its type, currency and postable flag (ACC-01). */
class UpdateAccountRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.manage';
    }

    public function rules(): array
    {
        $req = 'sometimes';
        $account = $this->route('acc_account');

        return [
            'code' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+\z/', Rule::unique('acc_accounts', 'code')->where('company_id', $this->company()->id)->ignore($account?->id)],
            'name' => [$req, 'string', 'max:150'],
            'type' => [$req, 'string', Rule::in(Account::TYPES)],
            'subtype' => [$req, 'string', Rule::in(Account::SUBTYPES)],
            'parent_id' => ['nullable', 'uuid', $this->ofCompany('acc_accounts')],
            'postable' => ['sometimes', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3', $this->activeCurrency()],
            'is_control' => ['sometimes', 'boolean'],
            'cash_flow' => ['nullable', 'string', Rule::in(Account::CASH_FLOWS)],
            'revalue' => ['sometimes', 'boolean'],
            'counterparty_company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id'), Rule::notIn([$this->company()->id])],
        ];
    }
}
