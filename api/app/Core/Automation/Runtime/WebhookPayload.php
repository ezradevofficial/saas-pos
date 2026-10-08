<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Carbon\CarbonImmutable;

/**
 * AUTO-03: the snapshot a webhook delivery sends. `fields` are the
 * document's values when the webhook action runs (after the rule's earlier
 * actions, inside the run's transaction), without the fields hidden from
 * the rule's user (RBAC-05). The snapshot is stored with the delivery row,
 * so every attempt, retries included, sends exactly the same body.
 */
class WebhookPayload
{
    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly FieldVisibility $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the envelope (event, id, rule, trigger, document)
     * @return array<string, mixed>
     */
    public function snapshot(array $payload, AutomationRule $rule): array
    {
        $document = $payload['document'] ?? null;
        $type = is_array($document) ? $this->types->find((string) $document['type']) : null;
        $fields = [];

        if ($type !== null) {
            $actor = $rule->actorId() === null ? null : User::query()->find($rule->actorId());
            $hidden = $this->visibility->hidden($actor, $type);
            $fields = FieldVisibility::without($type->fieldValues((string) $document['id']), $hidden);

            // Ids stay as they are; a reference also gets its display value as `<field>_label`.
            foreach (FieldVisibility::without($type->displayValuesOf($fields, $actor), $hidden) as $name => $label) {
                if (! array_key_exists($name.'_label', $fields)) {
                    $fields[$name.'_label'] = $label;
                }
            }
        }

        return [...$payload, 'occurred_at' => CarbonImmutable::now()->toIso8601ZuluString(), 'fields' => (object) $fields];
    }
}
