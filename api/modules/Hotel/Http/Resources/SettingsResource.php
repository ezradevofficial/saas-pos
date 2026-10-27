<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\Settings;

/**
 * HOT-02: a company's hotel settings (the stored ones, or the defaults while it has none).
 *
 * @mixin Settings
 */
class SettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Settings $s */
        $s = $this->resource;

        return [
            'company_id' => $s->company_id,
            'check_in_time' => $s->check_in_time,
            'check_out_time' => $s->check_out_time,
            'night_audit_time' => $s->night_audit_time,
            'early_checkin_fee_minor' => (string) $s->early_checkin_fee_minor,
            'late_checkout_fee_minor' => (string) $s->late_checkout_fee_minor,
            'fee_currency' => $s->fee_currency,
            'weekend_days' => array_values(array_map('intval', $s->weekend_days ?? [])),
            'cancel_free_days' => $s->cancel_free_days,
            'cancel_charge_kind' => $s->cancel_charge_kind,
            'cancel_charge_value' => (string) $s->cancel_charge_value,
            'noshow_charge_kind' => $s->noshow_charge_kind,
            'noshow_charge_value' => (string) $s->noshow_charge_value,
            'deposit_percent' => $s->deposit_percent,
            'require_guest_id' => $s->require_guest_id,
            'room_item_id' => $s->room_item_id,
            'levy_tax_code_ids' => array_values($s->levy_tax_code_ids ?? []),
            'charge_limit_minor' => $s->charge_limit_minor === null ? null : (string) $s->charge_limit_minor,
            'room_charge_method_id' => $s->room_charge_method_id,
        ];
    }
}
