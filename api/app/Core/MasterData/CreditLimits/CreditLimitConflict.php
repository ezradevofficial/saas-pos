<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Http\ApiException;

/**
 * An approved credit limit change that cannot be applied as asked: the
 * party's limit is now in another currency than the request's. Retrying
 * does not help; someone with set_directly sorts the party out and applies
 * the request again (POST credit-limit-changes/{id}/apply).
 */
class CreditLimitConflict extends ApiException
{
    public static function currency(CreditLimitChange $change, string $partyCurrency): self
    {
        return new self(422, 'credit_limit_conflict', __('core.credit_limit_change.errors.conflict', [
            'number' => $change->number, 'currency' => $partyCurrency, 'requested' => $change->requested_limit_currency,
        ]));
    }
}
