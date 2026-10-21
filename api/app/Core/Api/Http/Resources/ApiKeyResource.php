<?php

namespace App\Core\Api\Http\Resources;

use App\Core\Api\ApiUsage;
use App\Core\Api\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API-02: a key as the back office shows it: its prefix and id, never the
 * secret or a hash; the role and scope it acts with (none once revoked);
 * its scopes, expiry, last use and rotation overlap. Load `assignment.role`
 * and `creator`, and fill the assignment's scope name (ScopeNames).
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->assignment;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->display(),
            'mode' => $this->mode,
            'status' => $this->status(),
            'scopes' => $this->scopeList(),
            'role' => $assignment?->role === null ? null : ['id' => $assignment->role->id, 'name' => $assignment->role->name],
            'scope' => $assignment === null ? null : ['type' => $assignment->scope_type, 'id' => $assignment->scope_id, 'name' => $assignment->scopeName],
            'created_by' => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name],
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => app(ApiUsage::class)->lastUsedAt($this->resource)?->toIso8601String(),
            'rotated_at' => $this->rotated_at?->toIso8601String(),
            'previous_expires_at' => $this->previous_expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
