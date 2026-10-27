<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\NightAuditRun;

/**
 * HOT-30: a night audit and its occupancy snapshot.
 *
 * @mixin NightAuditRun
 */
class NightAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var NightAuditRun $a */
        $a = $this->resource;

        return [
            'id' => $a->id,
            'branch_id' => $a->branch_id,
            'business_date' => Refs::day($a->business_date),
            'status' => $a->status,
            'started_at' => Refs::at($a->started_at),
            'completed_at' => Refs::at($a->completed_at),
            'run_by' => $a->run_by,
            'scheduled' => $a->run_by === null,
            'summary' => $a->summary,
        ];
    }
}
