<?php

namespace App\Core\Automation\Http\Controllers;

use App\Core\Automation\Actions\AutomationAction;
use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\NotifyAction;
use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Automation\Http\Requests\AutomationViewRequest;
use App\Core\Automation\Runtime\FieldText;
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
 * notification can address, its date fields, its capabilities, and the
 * triggers and actions usable with it; plus the schedule options and the
 * notification placeholders.
 */
class AutomationCatalogueController
{
    public function __invoke(AutomationViewRequest $request, DocumentTypeRegistry $types, AutomationActions $actions): JsonResponse
    {
        return new JsonResponse([
            'data' => array_values(array_map(fn (DocumentType $type) => $this->type($type, $actions), $types->all())),
            'meta' => [
                'triggers' => array_map(fn (string $t) => ['key' => $t, 'label' => __('automation.triggers.types.'.$t)], Triggers::TYPES),
                'actions' => array_values(array_map(fn (AutomationAction $a) => [
                    'key' => $a->key(),
                    'label' => __('automation.actions.'.$a->key().'.label'),
                    'needs_document' => $a->needsDocument(),
                ], $actions->all())),
                'schedule' => ['every' => ScheduleRecurrence::EVERY, 'days' => ScheduleRecurrence::DAYS],
                'date_when' => Triggers::DATE_WHEN,
                'stage_how' => Triggers::STAGE_HOW,
                'recipient_prefixes' => ['role:', 'user:', 'field:'],
                'placeholders' => FieldText::BUILT_IN,
                'limits' => ['subject' => NotifyAction::SUBJECT_MAX, 'message' => NotifyAction::MESSAGE_MAX, 'actions' => 20, 'max_days' => Triggers::MAX_DAYS],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function type(DocumentType $type, AutomationActions $actions): array
    {
        $capabilities = Capabilities::of($type);
        $triggers = array_values(array_filter(Triggers::TYPES, fn (string $t) => $t !== Triggers::DATE || in_array(Capabilities::DATES, $capabilities, true)));
        $needs = [
            'update_field' => Capabilities::UPDATE_FIELDS,
            'assign_user' => Capabilities::ASSIGN_USERS,
            'set_credit_hold' => Capabilities::CREDIT_HOLD,
        ];

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
            'triggers' => $triggers,
            'actions' => array_values(array_filter(array_keys($actions->all()), fn (string $key) => ! isset($needs[$key]) || in_array($needs[$key], $capabilities, true))),
        ];
    }
}
