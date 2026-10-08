<?php

namespace App\Core\Tenancy\Http\Resources;

use App\Core\Tenancy\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device Never exposes the pairing code hash or the device secret. */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'name' => $this->name,
            'code' => $this->code,
            'status' => $this->status,
            'paired_at' => $this->paired_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            // NFR-04: when the device last pulled master data, pushed sales and installed.
            'last_pull_at' => $this->last_pull_at?->toIso8601String(),
            'last_push_at' => $this->last_push_at?->toIso8601String(),
            'last_bootstrap_at' => $this->last_bootstrap_at?->toIso8601String(),
        ];
    }
}
