<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\AttendanceDevice;

/**
 * HR-24, MKT-04: an attendance device as a connector sees it: where it is
 * and whether it takes punches. Its tokens are never shown.
 *
 * @mixin AttendanceDevice
 */
class ConnectorDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AttendanceDevice $device */
        $device = $this->resource;

        return [
            'id' => $device->id,
            'name' => $device->name,
            'company_id' => $device->company_id,
            'location' => ['id' => $device->location_id, 'name' => (string) $device->location?->name],
            'status' => $device->status,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }
}
