<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\AccountingSettings;

/** PUT companies/{company}/accounting/settings: the account of each role (ACC-02) and how POS sales post (ACC-26). */
class UpdateSettingsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.manage';
    }

    public function rules(): array
    {
        return [
            'roles' => ['required_without:sales_posting', 'array', 'min:1'],
            // ACC-26: one journal per POS sale, or a daily summary per location.
            'sales_posting' => ['sometimes', 'string', Rule::in([AccountingSettings::PER_SALE, AccountingSettings::DAILY_SUMMARY])],
            'roles.*.role' => ['required', 'string', 'distinct', Rule::in(AccountingSettings::ROLES)],
            'roles.*.account_id' => ['nullable', 'uuid', $this->ofCompany('acc_accounts')],
        ];
    }
}
