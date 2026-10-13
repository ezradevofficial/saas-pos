<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\MasterData\Parties\Party;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Loyalty\Models\Account;

/**
 * Credit accounts (LOY-14..LOY-17):
 *
 * - POST loyalty/accounts `{party_id, company_id}` opens one (`loyalty.credit.manage` at the company);
 * - POST loyalty/accounts/{loyalty_account}/payments `{amount_minor, reference?, note?, occurred_at?}`
 *   (`loyalty.credit.receive`), /charges `{amount_minor, reference?, note?}` (`loyalty.credit.charge`),
 *   /adjustments `{amount_minor: "-1250", reason}` (`loyalty.credit.adjust`);
 * - POST loyalty/accounts/{loyalty_account}/statements `{from, to}` (`loyalty.credit.view`), at most a year.
 *
 * Amounts are minor units of the account's currency, as digit strings.
 */
class AccountRequest extends LoyaltyRequest
{
    private const ACTIONS = [
        'payments' => 'loyalty.credit.receive',
        'charges' => 'loyalty.credit.charge',
        'adjustments' => 'loyalty.credit.adjust',
        'statements' => 'loyalty.credit.view',
    ];

    public function action(): string
    {
        return basename($this->route()->uri());
    }

    public function authorize(): bool
    {
        $account = $this->route('loyalty_account');

        if ($account === null) {
            $this->authorizeAnywhere('loyalty.credit.manage');

            return true;
        }

        abort_unless($account->kind === Account::CREDIT, 404);
        $this->authorizeAt($account->company_id, ['loyalty.credit.view'], self::ACTIONS[$this->action()] ?? abort(404));

        return true;
    }

    public function rules(): array
    {
        return match ($this->route('loyalty_account') === null ? 'open' : $this->action()) {
            'open' => [
                'party_id' => ['required', 'uuid', Rule::exists('parties', 'id')->whereNull('archived_at')],
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            ],
            'payments' => [
                'amount_minor' => self::minor(),
                'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
                'occurred_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            ],
            'charges' => [
                'amount_minor' => self::minor(),
                'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'adjustments' => [
                'amount_minor' => self::signedMinor(),
                'reason' => ['required', 'string', 'max:500'],
            ],
            'statements' => [
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.now()->addDay()->format('Y-m-d')],
            ],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->route('loyalty_account') === null) {
                $party = Party::query()->findOrFail($this->input('party_id'));
                $company = $this->input('company_id');

                if (! in_array('customer', (array) $party->roles, true) || ($party->company_id !== null && $party->company_id !== $company)) {
                    $validator->errors()->add('party_id', __('loyalty.validation.customer_company'));

                    return;
                }

                $this->authorizeAt($company, ['loyalty.credit.view', 'loyalty.credit.manage'], 'loyalty.credit.manage');
            }

            if ($this->action() === 'statements' && now()->parse($this->input('from'))->diffInDays(now()->parse($this->input('to'))) > 366) {
                $validator->errors()->add('to', __('loyalty.validation.statement_period'));
            }
        }];
    }
}
