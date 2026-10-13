<?php

namespace Modules\Loyalty\Http\Requests;

/**
 * One loyalty record read or acted on without a body (RBAC-04): GET a
 * promotion, its coupons, a credit account, a statement and its PDF, a
 * gift card; POST activate or archive a promotion, archive a coupon, close
 * an account, block or unblock a card. 404 for whoever cannot see the
 * record's company; 403 without the action's permission.
 */
class RecordRequest extends LoyaltyRequest
{
    /** route parameter => [view permissions, action => permission] */
    private const RECORDS = [
        'loyalty_promotion' => [['loyalty.promotion.view'], ['activate' => 'loyalty.promotion.edit', 'archive' => 'loyalty.promotion.archive']],
        'loyalty_coupon' => [['loyalty.promotion.view'], ['archive' => 'loyalty.promotion.edit']],
        'loyalty_account' => [['loyalty.credit.view'], ['close' => 'loyalty.credit.manage']],
        'loyalty_statement' => [['loyalty.credit.view'], []],
        'loyalty_gift_card' => [['loyalty.gift_card.view'], ['block' => 'loyalty.gift_card.block', 'unblock' => 'loyalty.gift_card.block']],
    ];

    public function authorize(): bool
    {
        foreach (self::RECORDS as $parameter => [$see, $actions]) {
            $record = $this->route($parameter);

            if ($record === null) {
                continue;
            }

            $action = $this->isMethod('GET') ? null : basename($this->route()->uri());
            $this->authorizeAt($record->company_id, $see, $action === null ? null : ($actions[$action] ?? abort(404)));

            return true;
        }

        abort(404);
    }

    public function rules(): array
    {
        return [];
    }
}
