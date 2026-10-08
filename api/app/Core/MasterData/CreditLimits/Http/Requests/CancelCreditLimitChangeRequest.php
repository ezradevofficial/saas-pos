<?php

namespace App\Core\MasterData\CreditLimits\Http\Requests;

use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;

/**
 * POST credit-limit-changes/{credit_limit_change}/cancel (WF-11): its
 * requester, or a holder of `core.credit_limit.request` at its company,
 * with a reason. Not seen: 404; seen but not theirs to cancel: 403.
 */
class CancelCreditLimitChangeRequest extends CreditLimitChangeRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(CreditLimitChangeAccess::class)->cancel($this->user(), $this->change());
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => __('core.credit_limit_change.attributes.cancel_reason')];
    }
}
