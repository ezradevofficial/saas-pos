<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Journal;

/**
 * IMP-01, ACC-20: one opening balance row (the import framework's per-row
 * check), as a journal line. Opening balances post without a flow, so they
 * need `accounting.journal.create` and either `accounting.journal.approve`
 * or `accounting.account.manage` at the company; they are dated on or
 * before the company's first other posting.
 */
class OpeningBalanceRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.journal.create';
    }

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $scope = Scope::company($this->company()->id);

        return $this->user()->can('accounting.journal.approve', $scope) || $this->user()->can('accounting.account.manage', $scope);
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'uuid', $this->ofCompany('acc_accounts')],
            'debit' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'credit' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?\z/'],
            'currency' => ['sometimes', 'string', 'size:3', $this->activeCurrency()],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?\z/'],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $date = $this->input('date');
            $first = Journal::query()->where('company_id', $this->company()->id)->where('status', Journal::POSTED)
                ->where('kind', '<>', 'opening')->min('date');

            if (is_string($date) && $first !== null && $date > substr((string) $first, 0, 10)) {
                $validator->errors()->add('date', __('accounting.errors.opening_after_postings', ['date' => substr((string) $first, 0, 10)]));
            }
        }];
    }
}
