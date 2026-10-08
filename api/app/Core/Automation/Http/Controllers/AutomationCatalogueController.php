<?php

namespace App\Core\Automation\Http\Controllers;

use App\Core\Automation\Actions\AutomationAction;
use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\ChangeStageAction;
use App\Core\Automation\Actions\CreateDocumentAction;
use App\Core\Automation\Actions\NotifyAction;
use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Automation\Http\Requests\AutomationViewRequest;
use App\Core\Automation\Runtime\FieldText;
use App\Core\Automation\Runtime\FlowStages;
use App\Core\Automation\Triggers\ScheduleRecurrence;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Http\JsonResponse;

/**
 * AUTO-01..AUTO-03: what the rule editor may offer, per document type of
 * the tenant's active modules: its fields (with the comparisons each
 * allows), the fields automation may write or assign, the user fields a
 * notification can address, its date fields, its capabilities, the stage
 * and approval nodes of its flows in the reader's companies (FlowStages),
 * and only the triggers and actions that can work with it: record
 * triggers for a type that raises record events, dates for one searchable
 * by date, "change stage" when a flow has a `stage` node, "create
 * document" when some type creates drafts. The rule validator refuses the
 * others at save. Plus the schedule options and notification placeholders.
 */
class AutomationCatalogueController
{
    public function __invoke(AutomationViewRequest $request, DocumentTypeRegistry $types, AutomationActions $actions, FlowStages $stages, AutomationAccess $access): JsonResponse
    {
        $companies = $access->companyIds($request->user());
        $createTargets = array_values(array_map(fn (DocumentType $t) => $t->key(), array_filter($types->all(), Capabilities::createsDrafts(...))));

        return new JsonResponse([
            'data' => array_values(array_map(fn (DocumentType $type) => $this->type($type, $actions, $stages->of($type->key(), $companies), $createTargets), $types->all())),
            'meta' => [
                'triggers' => array_map(fn (string $t) => ['key' => $t, 'label' => __('automation.triggers.types.'.$t)], Triggers::TYPES),
                'actions' => array_values(array_map(fn (AutomationAction $a) => [
                    'key' => $a->key(),
                    'label' => __('automation.actions.'.$a->key().'.label'),
                    'needs_document' => $a->needsDocument(),
                ], $actions->all())),
                'create_targets' => $createTargets,
                'schedule' => ['every' => ScheduleRecurrence::EVERY, 'days' => ScheduleRecurrence::DAYS],
                'date_when' => Triggers::DATE_WHEN,
                'stage_how' => Triggers::STAGE_HOW,
                'recipient_prefixes' => ['role:', 'user:', 'field:'],
                'placeholders' => FieldText::BUILT_IN,
                'limits' => ['subject' => NotifyAction::SUBJECT_MAX, 'message' => NotifyAction::MESSAGE_MAX, 'actions' => 20, 'max_days' => Triggers::MAX_DAYS],
            ],
        ]);
    }

    /**
     * @param  list<array{id: string, name: string, kind: string, company_id: ?string}>  $stages
     * @param  list<string>  $createTargets
     * @return array<string, mixed>
     */
    private function type(DocumentType $type, AutomationActions $actions, array $stages, array $createTargets): array
    {
        $capabilities = Capabilities::of($type);
        $needs = [
            'update_field' => Capabilities::UPDATE_FIELDS,
            'assign_user' => Capabilities::ASSIGN_USERS,
            'set_credit_hold' => Capabilities::CREDIT_HOLD,
        ];
        $usable = fn (string $key) => match ($key) {
            ChangeStageAction::KEY => in_array('stage', array_column($stages, 'kind'), true),
            CreateDocumentAction::KEY => $createTargets !== [],
            default => ! isset($needs[$key]) || in_array($needs[$key], $capabilities, true),
        };

        return [
            'key' => $type->key(),
            'label' => __($type->label()),
            'fields' => array_map(fn (FieldDefinition $field) => [
                ...$field->toArray(),
                'operators' => ConditionEvaluator::OPERATORS[$field->type],
            ], $type->fields()),
            'writable_fields' => Capabilities::writableFields($type),
            'assignable_fields' => Capabilities::assignableFields($type),
            'user_fields' => Capabilities::userFields($type),
            'date_fields' => Capabilities::dateFields($type),
            'capabilities' => $capabilities,
            'raises_record_events' => $type->raisesRecordEvents(),
            'stages' => $stages,
            'triggers' => array_values(array_filter(Triggers::TYPES, fn (string $t) => Triggers::supports($t, $type))),
            'actions' => array_values(array_filter(array_keys($actions->all()), $usable)),
        ];
    }
}
