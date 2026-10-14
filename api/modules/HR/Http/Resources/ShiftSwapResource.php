<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** HR-19: a shift swap. */
class ShiftSwapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => Refs::employee($this->employee),
            'peer' => Refs::employee($this->peer),
            'roster_shift' => $this->rosterShift === null ? null : RosterShiftResource::make($this->rosterShift)->resolve($request),
            'peer_roster_shift' => $this->peerRosterShift === null ? null : RosterShiftResource::make($this->peerRosterShift)->resolve($request),
            'reason' => $this->reason,
            'status' => $this->status,
            'answered_at' => Refs::at($this->answered_at),
            'decided_at' => Refs::at($this->decided_at),
            'created_at' => Refs::at($this->created_at),
        ];
    }
}
