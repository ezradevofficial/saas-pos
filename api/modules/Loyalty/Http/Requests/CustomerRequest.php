<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\MasterData\CompanyReach;

/**
 * The customer page's Loyalty tab (LOY-02, LOY-14): GET
 * loyalty/customers/{party} (`loyalty.points.view` or `loyalty.credit.view`;
 * the party must be one the user reaches, else 404).
 */
class CustomerRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $party = $this->route('party');

        // A customer of a company the user holds a loyalty permission at (any, when shared).
        abort_unless(app(CompanyReach::class)->reachesRecord($this->user(), $party->company_id, ['loyalty.points.view', 'loyalty.credit.view', 'loyalty.points.adjust']), 404);
        $this->authorizeAnywhere('loyalty.points.view', 'loyalty.credit.view');

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
