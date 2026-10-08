<?php

namespace App\Core\MasterData\CreditLimits\Http\Requests;

use App\Core\MasterData\CreditLimits\CreditLimitChanges;

/**
 * POST credit-limit-changes/{credit_limit_change}/apply: apply an approved
 * request whose party write failed, by a holder of
 * `core.credit_limit.set_directly` at its company. Not seen: 404; seen
 * without the permission: 403. No body.
 */
class ApplyCreditLimitChangeRequest extends CreditLimitChangeRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(CreditLimitChanges::class)->canSetDirectly($this->user(), $this->change()->company_id);
    }
}
