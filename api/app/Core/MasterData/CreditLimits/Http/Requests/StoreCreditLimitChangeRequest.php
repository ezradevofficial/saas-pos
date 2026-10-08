<?php

namespace App\Core\MasterData\CreditLimits\Http\Requests;

use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST credit-limit-changes: ask for a party's credit limit to change and
 * submit it to its flow (MD-01, WF-01). Body:
 *
 *   {party_id, company_id?, requested_limit: {amount_minor: "25000000", currency: "KES"}, reason}
 *
 * - `amount_minor` is a string of digits in minor units (ADR 003), never a
 *   number; the currency is active for the tenant and, when the party has
 *   a limit, the same as that limit's.
 * - The company is the party's own; for a shared party, the company the
 *   request is for (`company_id`), which may be left out when the user can
 *   request for one company only.
 * - Needs `core.credit_limit.request` anywhere (else 403), the party seen
 *   (else 404), the permission at a scope touching the company and the
 *   credit limit not hidden by field rules (else 403).
 */
class StoreCreditLimitChangeRequest extends FormRequest
{
    private ?Party $party = null;

    private ?string $companyId = null;

    public function authorize(): bool
    {
        return app(CreditLimitChangeAccess::class)->requestAnywhere($this->user());
    }

    public function rules(): array
    {
        return [
            'party_id' => ['required', 'uuid', Rule::exists('parties', 'id')->whereNull('archived_at')],
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'requested_limit' => ['required', 'array:amount_minor,currency'],
            // A string of digits that fits a bigint, never a JSON number (no floats).
            'requested_limit.amount_minor' => ['required', 'string', 'regex:/^\d{1,18}\z/'],
            'requested_limit.currency' => ['required', 'string', 'regex:/^[A-Z]{3}\z/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $access = app(CreditLimitChangeAccess::class);
            $party = Party::query()->findOrFail($this->input('party_id'));

            abort_unless(app(PartyPolicy::class)->reach($this->user(), $party), 404);

            $companyId = $this->resolveCompany($validator, $party, $access);

            if ($companyId === null) {
                return;
            }

            if (! $access->request($this->user(), $party, $companyId)) {
                throw new ApiException(403, 'forbidden', __('rbac.forbidden'));
            }

            CreditLimitChanges::assertRequestable($party->creditLimit(), $this->requestedLimit());

            $this->party = $party;
            $this->companyId = $companyId;
        }];
    }

    public function party(): Party
    {
        return $this->party;
    }

    public function companyId(): string
    {
        return $this->companyId;
    }

    public function requestedLimit(): Money
    {
        return Money::ofMinor((string) $this->input('requested_limit.amount_minor'), (string) $this->input('requested_limit.currency'));
    }

    public function attributes(): array
    {
        return [
            'party_id' => __('core.credit_limit_change.attributes.party'),
            'company_id' => __('core.credit_limit_change.attributes.company'),
            'requested_limit' => __('core.credit_limit_change.attributes.requested_limit'),
            'requested_limit.amount_minor' => __('core.credit_limit_change.attributes.requested_limit'),
            'requested_limit.currency' => __('core.credit_limit_change.attributes.currency'),
            'reason' => __('core.credit_limit_change.attributes.reason'),
        ];
    }

    public function messages(): array
    {
        return [
            'requested_limit.amount_minor.regex' => __('core.credit_limit_change.errors.amount'),
            'requested_limit.amount_minor.string' => __('core.credit_limit_change.errors.amount'),
            'requested_limit.currency.exists' => __('core.currency.not_active'),
        ];
    }

    /** The party's company; for a shared party, the one asked for or the user's only one. */
    private function resolveCompany(Validator $validator, Party $party, CreditLimitChangeAccess $access): ?string
    {
        $given = $this->input('company_id');

        if ($party->company_id !== null) {
            if ($given !== null && $given !== $party->company_id) {
                $validator->errors()->add('company_id', __('core.credit_limit_change.errors.company_of_party'));

                return null;
            }

            return $party->company_id;
        }

        if ($given !== null) {
            return $given;
        }

        $companies = $access->requestCompanies($this->user())
            ?? Company::query()->whereNull('archived_at')->pluck('id')->all();
        $companies = Company::query()->whereKey($companies)->whereNull('archived_at')->pluck('id')->all();

        if (count($companies) !== 1) {
            $validator->errors()->add('company_id', __('core.credit_limit_change.errors.company_required'));

            return null;
        }

        return (string) $companies[0];
    }
}
