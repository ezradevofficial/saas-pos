<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\Account;

/** @mixin Account */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'subtype' => $this->subtype,
            'parent_id' => $this->parent_id,
            'postable' => $this->postable,
            'currency' => $this->currency,
            'is_control' => $this->is_control,
            'cash_flow' => $this->cash_flow,
            'revalue' => $this->revalue,
            'counterparty_company_id' => $this->counterparty_company_id,
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
