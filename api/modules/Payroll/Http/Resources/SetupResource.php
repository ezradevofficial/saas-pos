<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\Item;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\Settings;

/** PAY-02 to PAY-04: settings, pay groups and items as the API shows them. */
class SetupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return match (true) {
            $row instanceof PayGroup => [
                'id' => $row->id,
                'company' => Refs::named($row->relationLoaded('company') ? $row->company : null),
                'company_id' => $row->company_id,
                'code' => $row->code,
                'name' => $row->name,
                'frequency' => $row->frequency,
                'anchor_date' => $row->anchor_date?->toDateString(),
                'pay_day' => $row->pay_day,
                'archived_at' => $row->archived_at?->toIso8601String(),
            ],
            $row instanceof Item => [
                'id' => $row->id,
                'company' => Refs::named($row->relationLoaded('company') ? $row->company : null),
                'company_id' => $row->company_id,
                'code' => $row->code,
                'name' => $row->name,
                'kind' => $row->kind,
                'taxable' => $row->taxable,
                'contributory' => $row->contributory,
                'pre_tax' => $row->pre_tax,
                'insurance_premium' => $row->insurance_premium,
                'archived_at' => $row->archived_at?->toIso8601String(),
            ],
            $row instanceof Settings => [
                'company_id' => $row->company_id,
                'overtime_multiplier' => $row->overtime_multiplier === null ? null : (string) $row->overtime_multiplier,
                'night_multiplier' => $row->night_multiplier === null ? null : (string) $row->night_multiplier,
                'holiday_multiplier' => $row->holiday_multiplier === null ? null : (string) $row->holiday_multiplier,
                'rest_day_multiplier' => $row->rest_day_multiplier === null ? null : (string) $row->rest_day_multiplier,
                'monthly_hours' => $row->monthly_hours === null ? null : (string) $row->monthly_hours,
                'monthly_days' => $row->monthly_days === null ? null : (string) $row->monthly_days,
                'premiums_taxable' => (bool) $row->premiums_taxable,
                'premiums_contributory' => (bool) $row->premiums_contributory,
            ],
            default => [],
        };
    }
}
