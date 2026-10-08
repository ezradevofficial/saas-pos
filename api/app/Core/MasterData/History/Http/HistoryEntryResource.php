<?php

namespace App\Core\MasterData\History\Http;

use App\Core\Audit\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One change of a record (MD-07). `actor_name` is joined from users by
 * HistoryController.
 *
 * @mixin AuditEntry
 */
class HistoryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->user_id === null ? null : ['id' => $this->user_id, 'name' => $this->actor_name],
            'before' => $this->before,
            'after' => $this->after,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
