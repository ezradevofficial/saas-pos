<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\PayAccount;
use Modules\HR\Support\Sensitive;

/** HR-04: a pay account; the number in full only with `hr.bank.view` at the employee's place. */
class PayAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::present($this->resource, true);
    }

    /** @return array<string, mixed> */
    public static function present(PayAccount $account, bool $full): array
    {
        return [
            'id' => $account->id,
            'kind' => $account->kind,
            'provider' => $account->provider,
            'branch_code' => $account->branch_code,
            'account_name' => $account->account_name,
            'account_number' => $full ? $account->account_number : null,
            'masked' => Sensitive::mask($account->account_hint),
            'currency' => $account->currency,
            'is_primary' => $account->is_primary,
            'archived_at' => Refs::at($account->archived_at),
        ];
    }
}
