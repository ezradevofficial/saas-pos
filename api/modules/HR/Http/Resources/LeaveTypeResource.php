<?php

namespace Modules\HR\Http\Resources;

use App\Core\CountryPacks\ResearchStatus;
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
            'pay_share' => $this->pay_share,
            'seniority' => $this->seniority,
            'accrual' => $this->accrual,
            'entitlement_days' => $this->entitlement_days === null ? null : (string) $this->entitlement_days,
            'days_needed' => (bool) $this->needs_confirmation,
            'count_basis' => $this->count_basis,
            'carry_over_max_days' => $this->carry_over_max_days === null ? null : (string) $this->carry_over_max_days,
            'allow_negative' => $this->allow_negative,
            'requires_document' => $this->requires_document,
            'accrues_from' => Refs::date($this->accrues_from),
            'qualifying_months' => $this->qualifying_months,
            'half_pay_days' => $this->half_pay_days === null ? null : (string) $this->half_pay_days,
            'events' => array_map(fn (array $e) => [...$e, 'label' => HrPack::label('leave_events', $e['key'])], $this->events ?? []),
            // CP-01: days from public research, not yet confirmed (the web app shows the notice).
            'researched' => $this->researched(),
            'research' => $this->research,
            'low_confidence' => ResearchStatus::lowResearch($this->research),
            'archived_at' => Refs::at($this->archived_at),
        ];
    }
}
