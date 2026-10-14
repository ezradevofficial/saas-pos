<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Validation\Rule;
use Modules\Accounting\Http\Lists\AccountList;
use Modules\Accounting\Models\Account;

/** GET companies/{company}/accounting/accounts: the chart (ACC-01), `?type=`, `?status=active|archived|all`, search by code or name, export. */
class ListAccountsRequest extends ListRequest
{
    public function list(): ListDefinition
    {
        return new AccountList;
    }

    protected function statuses(): array
    {
        return ['active', 'archived'];
    }

    protected function permission(): string
    {
        return 'accounting.account.view';
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'type' => ['sometimes', 'string', Rule::in(Account::TYPES)],
        ];
    }
}
