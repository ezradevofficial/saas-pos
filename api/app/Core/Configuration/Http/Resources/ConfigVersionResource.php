<?php

namespace App\Core\Configuration\Http\Resources;

use App\Core\Configuration\Models\ConfigVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A configuration version (LAY-06). The payload only when asked for
 * (withPayload): history lists leave it out.
 *
 * @mixin ConfigVersion
 */
class ConfigVersionResource extends JsonResource
{
    private bool $withPayload = false;

    public function withPayload(bool $payload = true): static
    {
        $this->withPayload = $payload;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status,
            'source' => $this->source,
            'source_version_id' => $this->source_version_id,
            'created_by' => $this->created_by,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'discarded_at' => $this->discarded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // An empty JSON object stays an object.
            'payload' => $this->when($this->withPayload, fn () => $this->resource->payload === [] ? (object) [] : $this->resource->payload),
        ];
    }
}
