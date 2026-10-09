<?php

namespace App\Core\MasterData\History\Http;

use App\Core\Audit\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One change of a record (MD-07). `actor_name` is joined from users and
 * `hiddenFields` (RBAC-05) and `hiddenCustomFields` (custom field keys,
 * CF-03) set by HistoryController.
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
            'before' => self::visible($this->before, $this->hiddenFields ?? [], $this->hiddenCustomFields ?? []),
            'after' => self::visible($this->after, $this->hiddenFields ?? [], $this->hiddenCustomFields ?? []),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $hidden  fields
     * @param  list<string>  $hiddenCustom  custom field keys (CF-03), removed from `custom`
     */
    private static function visible(?array $values, array $hidden, array $hiddenCustom = []): ?array
    {
        if ($values === null) {
            return null;
        }

        $values = array_diff_key($values, array_flip($hidden));

        if ($hiddenCustom !== [] && is_array($values['custom'] ?? null)) {
            $values['custom'] = array_diff_key($values['custom'], array_flip($hiddenCustom));
        }

        return $values;
    }
}
