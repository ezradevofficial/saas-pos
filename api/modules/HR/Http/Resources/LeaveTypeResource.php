<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Support\HrPack;

/** HR-12: a leave type; `days_needed` while its days are not confirmed. */
class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'statutory_key' => $this->statutory_key,
            'statutory_label' => $this->statutory_key === null ? null : HrPack::label('leave_types', $this->statutory_key),
            'paid' => $this->paid,
            'accrual' => $this->accrual,
            'entitlement_days' => $this->entitlement_days === null ? null : (string) $this->entitlement_days,
            'days_needed' => (bool) $this->needs_confirmation,
            'count_basis' => $this->count_basis,
            'carry_over_max_days' => $this->carry_over_max_days === null ? null : (string) $this->carry_over_max_days,
            'allow_negative' => $this->allow_negative,
            'requires_document' => $this->requires_document,
            'accrues_from' => Refs::date($this->accrues_from),
            'archived_at' => Refs::at($this->archived_at),
        ];
    }
}
