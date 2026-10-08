<?php

namespace App\Core\MasterData\History\Http;

use App\Core\Audit\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One change of a record (MD-07). `actor_name` is joined from users and
 * `hiddenFields` (RBAC-05) set by HistoryController.
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
            'before' => self::visible($this->before, $this->hiddenFields ?? []),
            'after' => self::visible($this->after, $this->hiddenFields ?? []),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }

    /** @param list<string> $hidden */
    private static function visible(?array $values, array $hidden): ?array
    {
        return $values === null ? null : array_diff_key($values, array_flip($hidden));
    }
}
