<?php

namespace App\Core\Identity\Http\Resources;

use App\Core\Identity\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PersonalAccessToken */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'current' => $this->id === $request->user()?->currentAccessToken()?->getKey(),
        ];
    }
}
