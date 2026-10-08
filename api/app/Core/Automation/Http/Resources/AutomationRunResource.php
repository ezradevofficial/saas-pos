<?php

namespace App\Core\Automation\Http\Resources;

use App\Core\Automation\Actions\WebhookAction;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Models\WebhookDelivery;
use App\Core\Automation\Runtime\FieldVisibility;
use App\Core\Rbac\Scope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One run of a rule (AUTO-05): trigger, document, outcome, per-action
 * results, webhook deliveries, the safe error and attempts, and its chain
 * (AUTO-06). For the reader (RBAC-05): condition checks and trigger
 * details on fields their field rules hide are left out, and webhook
 * answers are shown only to people who edit automation for the whole
 * tenant.
 *
 * @mixin AutomationRun
 */
class AutomationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hidden = $this->hidden($request);

        return [
            'id' => $this->id,
            'rule_id' => $this->rule_id,
            'rule_name' => $this->rule?->name,
            'rule_version' => $this->rule_version,
            'trigger_type' => $this->trigger_type,
            'trigger' => (object) $this->triggerShown($hidden),
            'document_type' => $this->document_type,
            'document_id' => $this->document_id,
            'company_id' => $this->company_id,
            'outcome' => $this->outcome,
            'conditions' => $this->conditions === null ? null : [
                ...$this->conditions,
                'checks' => array_values(array_filter($this->conditions['checks'] ?? [], fn (array $check) => ! in_array($check['field'] ?? null, $hidden, true))),
            ],
            'actions' => $this->actions ?? [],
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn (WebhookDelivery $d) => [
                'id' => $d->id,
                'action_index' => $d->action_index,
                'url_display' => WebhookAction::shown($d->url),
                'status' => $d->status,
                'attempts' => $d->attempts,
                'response_status' => $d->response_status,
                'response_body' => $this->seesResponses($request) ? $d->response_body : null,
                'error' => $d->error,
                'delivered_at' => $d->delivered_at?->toIso8601ZuluString(),
            ])->values()->all()),
            'error' => $this->error,
            'error_code' => $this->error_code,
            'attempts' => $this->attempts,
            'chain_id' => $this->chain_id,
            'depth' => $this->depth,
            // AUTO-06: a run started by another rule's action (its chain holds a rule), not by a person's change, a workflow move or a schedule.
            'caused_by_rule' => ($this->chain ?? []) !== [],
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /** @param list<string> $hidden */
    private function triggerShown(array $hidden): array
    {
        $trigger = $this->trigger ?? [];

        if (in_array($trigger['field'] ?? null, $hidden, true)) {
            unset($trigger['field']);
        }

        if (isset($trigger['fields']) && is_array($trigger['fields'])) {
            $trigger['fields'] = array_values(array_diff($trigger['fields'], $hidden));
        }

        return $trigger;
    }

    /** @return list<string> fields of the rule's type hidden from the reader (cached per request and type) */
    private function hidden(Request $request): array
    {
        $type = $this->rule === null ? null : app(DocumentTypeRegistry::class)->find($this->rule->document_type);

        if ($type === null || $request->user() === null) {
            return [];
        }

        $key = 'automation.hidden.'.$type->key();

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(FieldVisibility::class)->hidden($request->user(), $type));
        }

        return $request->attributes->get($key);
    }

    private function seesResponses(Request $request): bool
    {
        if (! $request->attributes->has('automation.sees_responses')) {
            $request->attributes->set('automation.sees_responses', (bool) $request->user()?->can('core.automation.edit', Scope::tenant()));
        }

        return $request->attributes->get('automation.sees_responses');
    }
}
