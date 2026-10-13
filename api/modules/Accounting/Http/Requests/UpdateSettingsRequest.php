<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Accounting\Models\AccountingSettings;

/** PUT companies/{company}/accounting/settings: the account of each role (ACC-02). */
class UpdateSettingsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.manage';
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.role' => ['required', 'string', 'distinct', Rule::in(AccountingSettings::ROLES)],
            'roles.*.account_id' => ['nullable', 'uuid', $this->ofCompany('acc_accounts')],
        ];
    }
}
