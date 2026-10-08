<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Chain\AutomationChain;
use App\Core\Automation\Chain\Cause;
use App\Core\Automation\Events\RecordChanged;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Triggers\TriggerHit;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowStageEntered;
use App\Core\Workflow\Events\WorkflowStageLeft;

/**
 * AUTO-01: record changes (RecordChanged) and stage moves (the workflow
 * engine's events), both after commit, fire the matching live rules of
 * the document's type in the tenant, for the document's company or every
 * company. Each fired rule is logged and queued by RuleRunner, with the
 * chain the change belongs to (AUTO-06): captured in RecordChanged, or the
 * chain running now for a stage move made by a rule's action.
 */
class TriggerListener
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DocumentTypeRegistry $types,
        private readonly Triggers $triggers,
        private readonly RuleRunner $runner,
        private readonly AutomationChain $chain,
        private readonly RuleTimezone $timezones,
    ) {}

    public function recordChanged(RecordChanged $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            $cause = Cause::fromArray($event->cause);

            $this->each($event->documentType, $event->documentId, Triggers::RECORD_TRIGGERS, function (AutomationRule $rule, DocumentType $type, DocumentScope $scope) use ($event) {
                $timezone = $this->timezones->forCompany($scope->companyId ?? $rule->company_id) ?? 'UTC';

                return $this->triggers->matchesChange($rule->trigger, $event->change, $event->old, $event->new, $type, $timezone);
            }, $cause);
        });
    }

    public function stageEntered(WorkflowStageEntered $event): void
    {
        $cause = $this->chain->current();

        $this->tenants->run($event->tenantId, fn () => $this->each($event->documentType, $event->documentId, [Triggers::STAGE_ENTERED],
            fn (AutomationRule $rule) => $this->triggers->matchesStage($rule->trigger, 'entered', $event->nodeId), $cause));
    }

    public function stageLeft(WorkflowStageLeft $event): void
    {
        $cause = $this->chain->current();

        $this->tenants->run($event->tenantId, fn () => $this->each($event->documentType, $event->documentId, [Triggers::STAGE_LEFT],
            fn (AutomationRule $rule) => $this->triggers->matchesStage($rule->trigger, 'left', $event->nodeId, $event->how, $event->outcome), $cause));
    }

    /**
     * @param  list<string>  $triggerTypes
     * @param  callable(AutomationRule, DocumentType, DocumentScope): ?array  $match
     */
    private function each(string $documentType, string $documentId, array $triggerTypes, callable $match, ?Cause $cause): void
    {
        $rules = AutomationRule::query()->live()
            ->where('document_type', $documentType)
            ->whereIn('trigger_type', $triggerTypes)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $type = $this->types->find($documentType);
        $scope = $type?->scope($documentId);

        if ($type === null || $scope === null) {
            return;
        }

        foreach ($rules as $rule) {
            if ($rule->company_id !== null && $rule->company_id !== $scope->companyId) {
                continue;
            }

            $details = $match($rule, $type, $scope);

            if ($details !== null) {
                $this->runner->dispatch($rule, new TriggerHit($rule->trigger_type, $details, $documentId), $cause);
            }
        }
    }
}
