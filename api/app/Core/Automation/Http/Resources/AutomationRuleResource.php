<?php

namespace App\Core\Automation\Http\Resources;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Runtime\ActionList;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An automation rule (AUTO-01..AUTO-03) as the editor reads it. The webhook
 * secret is returned once, in the response that generated it
 * (`webhook_secret`); after that only `has_webhook_secret` says one is set.
 *
 * @mixin AutomationRule
 */
class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = app(DocumentTypeRegistry::class)->find($this->document_type);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'document_type' => $this->document_type,
            'document_type_label' => $type === null ? $this->document_type : __($type->label()),
            'company_id' => $this->company_id,
            'company_name' => $this->company_id === null ? null : $this->company?->name,
            'trigger' => $this->trigger,
            'trigger_description' => $type === null ? null : app(Triggers::class)->describe($this->trigger, $type),
            'conditions' => $this->conditions,
            // Webhook URLs are write-only: url_display and has_url (ActionList).
            'actions' => ActionList::shown($this->actions),
            'enabled' => $this->enabled,
            'status' => $this->archived_at !== null ? 'archived' : ($this->enabled ? 'enabled' : 'disabled'),
            'version' => $this->version,
            'has_webhook_secret' => $this->webhook_secret !== null,
            // Only in the response that created or rotated it.
            'webhook_secret' => $this->when($this->resource->revealedSecret !== null, fn () => $this->resource->revealedSecret),
            'next_run_at' => $this->next_run_at?->toIso8601ZuluString(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
        ];
    }
}
