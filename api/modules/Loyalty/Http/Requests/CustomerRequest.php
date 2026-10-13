<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\MasterData\CompanyReach;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The customer page's Loyalty tab (LOY-02, LOY-14): GET
 * loyalty/customers/{party} (`loyalty.points.view` or `loyalty.credit.view`;
 * the party must be one the user reaches, else 404) and POST
 * loyalty/customers/{party}/points/adjust (`loyalty.points.adjust` at the
 * company; `{company_id, points: "-120", reason}`).
 */
class CustomerRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $party = $this->route('party');

        // A customer of a company the user holds a loyalty permission at (any, when shared).
        abort_unless(app(CompanyReach::class)->reachesRecord($this->user(), $party->company_id, ['loyalty.points.view', 'loyalty.credit.view', 'loyalty.points.adjust']), 404);
        $this->authorizeAnywhere(...($this->isMethod('GET') ? ['loyalty.points.view', 'loyalty.credit.view'] : ['loyalty.points.adjust']));

        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'points' => ['required', 'string', 'regex:/^-?\d{1,12}\z/', 'not_regex:/^-?0+$/'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->isMethod('GET') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $party = $this->route('party');
            $company = $this->input('company_id');

            if (! in_array('customer', (array) $party->roles, true) || ($party->company_id !== null && $party->company_id !== $company)) {
                $validator->errors()->add('company_id', __('loyalty.validation.customer_company'));

                return;
            }

            $this->authorizeAt($company, ['loyalty.points.view', 'loyalty.points.adjust'], 'loyalty.points.adjust');
        }];
    }
}
