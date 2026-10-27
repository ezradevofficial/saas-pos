<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\Reservation;

/**
 * HOT-12: a booking, its contact guest, its stays and its folios (a master folio for a group).
 *
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Reservation $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind,
            'branch' => Refs::place($r->relationLoaded('branch') ? $r->branch : null) ?? ['id' => $r->branch_id, 'name' => null],
            'guest' => $r->relationLoaded('guest') && $r->guest !== null ? ['id' => $r->guest->id, 'name' => $r->guest->name] : ['id' => $r->guest_id],
            'group_name' => $r->group_name,
            'source' => $r->source,
            'notes' => $r->notes,
            'stays_count' => $r->stays_count ?? ($r->relationLoaded('stays') ? $r->stays->count() : null),
            'created_at' => Refs::at($r->created_at),
            ...($r->relationLoaded('stays') ? ['stays' => $r->stays->map(fn ($stay) => StayResource::make($stay)->detail()->toArray($request))->values()->all()] : []),
            ...($this->detail && $r->relationLoaded('folios') ? ['folios' => $r->folios->map(fn (Folio $f) => [
                'id' => $f->id, 'number' => $f->number, 'kind' => $f->kind, 'stay_id' => $f->stay_id, 'status' => $f->status, 'currency' => $f->currency,
            ])->values()->all()] : []),
        ];
    }
}
