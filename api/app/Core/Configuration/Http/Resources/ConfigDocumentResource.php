<?php

namespace App\Core\Configuration\Http\Resources;

use App\Core\Configuration\Models\ConfigDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A configuration document (LAY-06): its kind, key, scope and name, its
 * published version and draft (with payloads when withPayloads()), and
 * its history when loaded.
 *
 * @mixin ConfigDocument
 */
class ConfigDocumentResource extends JsonResource
{
    private bool $payloads = false;

    public function withPayloads(): static
    {
        $this->payloads = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $version = fn (string $relation) => $this->whenLoaded($relation, fn () => $this->{$relation} === null
            ? null
            : ConfigVersionResource::make($this->{$relation})->withPayload($this->payloads)->resolve($request));

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'key' => $this->key,
            'name' => $this->name,
            'scope' => ['type' => $this->scope_type, 'id' => $this->scope_id],
            'published' => $version('published'),
            'draft' => $version('draft'),
            'history' => $this->whenLoaded('versions', fn () => ConfigVersionResource::collection($this->versions->sortByDesc('version')->values())->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
