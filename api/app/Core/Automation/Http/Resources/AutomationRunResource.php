<?php

namespace App\Core\Automation\Http\Resources;

use App\Core\Automation\Models\AutomationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One run of a rule (AUTO-05): trigger, document, outcome, per-action
 * results, the safe error and attempts, and its chain (AUTO-06).
 *
 * @mixin AutomationRun
 */
class AutomationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->rule_id,
            'rule_name' => $this->rule?->name,
            'rule_version' => $this->rule_version,
            'trigger_type' => $this->trigger_type,
            'trigger' => (object) ($this->trigger ?? []),
            'document_type' => $this->document_type,
            'document_id' => $this->document_id,
            'outcome' => $this->outcome,
            'conditions' => $this->conditions,
            'actions' => $this->actions ?? [],
            'error' => $this->error,
            'attempts' => $this->attempts,
            'chain_id' => $this->chain_id,
            'depth' => $this->depth,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
