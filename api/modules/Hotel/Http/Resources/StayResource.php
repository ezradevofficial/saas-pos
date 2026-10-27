<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\Stay;

/**
 * HOT-12, HOT-17: a stay (one room for a date range) with its quoted nights.
 * The guest is named (never their ID document: that is the guest record's).
 *
 * @mixin Stay
 */
class StayResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Stay $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'reservation_id' => $s->reservation_id,
            'reservation_number' => $s->relationLoaded('reservation') ? $s->reservation?->number : null,
            'branch_id' => $s->branch_id,
            'status' => $s->status,
            'guest' => $s->relationLoaded('guest') && $s->guest !== null ? ['id' => $s->guest->id, 'name' => $s->guest->name] : ['id' => $s->guest_id],
            'room_type' => $s->relationLoaded('roomType') && $s->roomType !== null ? ['id' => $s->roomType->id, 'code' => $s->roomType->code, 'name' => $s->roomType->name] : ['id' => $s->room_type_id],
            'rate_plan' => $s->relationLoaded('ratePlan') && $s->ratePlan !== null ? ['id' => $s->ratePlan->id, 'code' => $s->ratePlan->code, 'name' => $s->ratePlan->name] : ['id' => $s->rate_plan_id],
            'room' => $s->relationLoaded('room') && $s->room !== null ? ['id' => $s->room->id, 'number' => $s->room->number, 'status' => $s->room->status] : ($s->room_id === null ? null : ['id' => $s->room_id]),
            'arrival_on' => Refs::day($s->arrival_on),
            'departure_on' => Refs::day($s->departure_on),
            'nights' => $s->nights(),
            'adults' => $s->adults,
            'children' => $s->children,
            'board' => $s->board,
            'currency' => $s->currency,
            'total_minor' => (string) $s->total_minor,
            'rate_overridden' => $s->rate_overridden,
            'bill_to_master' => $s->bill_to_master,
            'keys_issued' => $s->keys_issued,
            'checked_in_at' => Refs::at($s->checked_in_at),
            'checked_out_at' => Refs::at($s->checked_out_at),
            'cancelled_at' => Refs::at($s->cancelled_at),
            'cancel_reason' => $s->cancel_reason,
            'no_show_at' => Refs::at($s->no_show_at),
            'notes' => $s->notes,
            'folio_id' => $s->relationLoaded('folio') ? $s->folio?->id : null,
            ...($this->detail ? ['nightly' => array_map(fn (array $n) => [
                'date' => $n['date'], 'rate_minor' => (string) $n['rate_minor'], 'extra_minor' => (string) $n['extra_minor'], 'amount_minor' => (string) $n['amount_minor'], 'season' => $n['season'] ?? null,
            ], (array) $s->nightly)] : []),
        ];
    }
}
