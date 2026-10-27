<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\RateSeason;

/**
 * HOT-06 to HOT-09: a rate plan with its seasons. Rates are minor-unit strings.
 *
 * @mixin RatePlan
 */
class RatePlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RatePlan $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'company_id' => $p->company_id,
            'room_type' => $p->relationLoaded('roomType') && $p->roomType !== null ? ['id' => $p->roomType->id, 'code' => $p->roomType->code, 'name' => $p->roomType->name] : ['id' => $p->room_type_id],
            'code' => $p->code,
            'name' => $p->name,
            'board' => $p->board,
            'currency' => $p->currency,
            'weekday_rate_minor' => (string) $p->weekday_rate_minor,
            'weekend_rate_minor' => (string) $p->weekend_rate_minor,
            'extra_adult_minor' => (string) $p->extra_adult_minor,
            'child_rate_minor' => (string) $p->child_rate_minor,
            'free_children' => $p->free_children,
            'tax_inclusive' => $p->tax_inclusive,
            'valid_from' => Refs::day($p->valid_from),
            'valid_until' => Refs::day($p->valid_until),
            'cancel_free_days' => $p->cancel_free_days,
            'cancel_charge_kind' => $p->cancel_charge_kind,
            'cancel_charge_value' => $p->cancel_charge_value === null ? null : (string) $p->cancel_charge_value,
            'noshow_charge_kind' => $p->noshow_charge_kind,
            'noshow_charge_value' => $p->noshow_charge_value === null ? null : (string) $p->noshow_charge_value,
            'active' => $p->active,
            'seasons' => $p->relationLoaded('seasons') ? $p->seasons->map(fn (RateSeason $s) => [
                'id' => $s->id, 'name' => $s->name, 'starts_on' => Refs::day($s->starts_on), 'ends_on' => Refs::day($s->ends_on),
                'weekday_rate_minor' => (string) $s->weekday_rate_minor, 'weekend_rate_minor' => $s->weekend_rate_minor === null ? null : (string) $s->weekend_rate_minor, 'priority' => $s->priority,
            ])->values()->all() : [],
            'archived_at' => Refs::at($p->archived_at),
        ];
    }
}
