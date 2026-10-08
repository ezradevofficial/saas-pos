<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\AutomationContext;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Triggers\FieldChange;
use App\Core\Automation\Triggers\ScheduleRecurrence;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Identity\Models\User;
use App\Core\Workflow\Conditions\ConditionCheck;
use App\Core\Workflow\Conditions\ConditionDescriber;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\Conditions\ConditionResult;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Carbon\CarbonImmutable;

/**
 * AUTO-04 test mode: what a rule would do for sample values or a real
 * document, without doing anything: no database writes, no HTTP, no
 * notifications, no run logged. The trigger is checked where the sample
 * can tell (a change needs the values before it, `old_values`; a stage
 * move cannot be simulated), the conditions are evaluated and explained,
 * and each action describes what it would do.
 *
 * RBAC-05: fields hidden by field rules from the rule's user (its last
 * editor; the tester for an unsaved rule) or from the tester are left out
 * of everything shown: action descriptions are built without them, and a
 * comparison on such a field shows neither value nor reason.
 */
class RuleTester
{
    public function __construct(
        private readonly Triggers $triggers,
        private readonly FieldChange $change,
        private readonly ConditionEvaluator $conditions,
        private readonly ConditionDescriber $describer,
        private readonly AutomationActions $actions,
        private readonly RuleTimezone $timezones,
        private readonly FieldVisibility $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $values  the document's (or the sample's) values
     * @param  array<string, mixed>|null  $oldValues  the values before a change, for change triggers
     * @return array<string, mixed>
     */
    public function test(AutomationRule $rule, DocumentType $type, ?string $documentId, ?DocumentScope $scope, array $values, ?array $oldValues, User $viewer): array
    {
        $actor = $rule->actorId() === null ? $viewer
            : User::query()->whereKey($rule->actorId())->where('status', User::STATUS_ACTIVE)->first();
        $hidden = array_values(array_unique([...$this->visibility->hidden($actor, $type), ...$this->visibility->hidden($viewer, $type)]));

        $trigger = $rule->trigger;
        $hasDocument = Triggers::hasDocument($trigger);
        $scope ??= new DocumentScope($rule->company_id);
        $timezone = $this->timezones->forCompany($scope->companyId ?? $rule->company_id) ?? 'UTC';
        $fields = $type->fieldsByName();

        [$matches, $details, $nextRun] = $this->trigger($trigger, $type, $values, $oldValues, $timezone, $rule->company_id);

        $conditions = null;
        $passed = true;

        if ($hasDocument) {
            $result = $this->conditions->evaluate($rule->conditions, $values, $fields, $timezone);
            $passed = $result->passed;
            $conditions = $this->conditionsShown($result, $fields, $timezone, $hidden);
        }

        $context = new AutomationContext(
            $rule, null, $type, $hasDocument ? ($documentId ?? 'sample') : null, $scope,
            $hasDocument ? $values : [], $actor, $timezone, app()->getLocale(), $hidden,
        );

        return [
            'trigger' => [
                'type' => $trigger['type'] ?? null,
                'description' => $this->triggers->describe($trigger, $type),
                'matches' => $matches,
                'details' => $details,
                'next_run_at' => $nextRun,
            ],
            'conditions' => $conditions,
            'would_run' => $passed && $matches !== false,
            'actions' => array_map(function (array $action) use ($context) {
                $handler = $this->actions->find((string) ($action['type'] ?? ''));

                return [
                    'type' => $action['type'] ?? null,
                    'description' => $handler?->describe($action, $context) ?? __('automation.errors.action_unavailable'),
                ];
            }, array_values($rule->actions)),
        ];
    }

    /**
     * The conditions' outcome without the values or reasons of hidden fields.
     *
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    private function conditionsShown(ConditionResult $result, array $fields, string $timezone, array $hidden): array
    {
        $shown = fn (ConditionCheck $check) => in_array($check->field, $hidden, true) || in_array($check->other, $hidden, true)
            ? ['field' => $check->field, 'op' => $check->op, 'passed' => $check->passed, 'hidden' => true]
            : $check->toArray();
        $visibleFailures = array_values(array_filter($result->failures, fn (ConditionCheck $c) => ! in_array($c->field, $hidden, true) && ! in_array($c->other, $hidden, true)));
        $reasons = $this->describer->reasons(new ConditionResult($result->passed, $result->checks, $visibleFailures), $fields, $timezone);

        if (count($visibleFailures) < count($result->failures)) {
            $reasons[] = __('automation.test.hidden_condition');
        }

        return [
            'passed' => $result->passed,
            'checks' => array_map($shown, $result->checks),
            'failures' => array_map($shown, $result->failures),
            'reasons' => $reasons,
        ];
    }

    /** @return array{0: ?bool, 1: ?array, 2: ?string} matches, details, next run */
    private function trigger(array $trigger, DocumentType $type, array $values, ?array $old, string $timezone, ?string $companyId): array
    {
        $kind = $trigger['type'] ?? null;

        switch ($kind) {
            case Triggers::RECORD_CREATED:
            case Triggers::RECORD_ARCHIVED:
                return [true, null, null];

            case Triggers::RECORD_UPDATED:
            case Triggers::FIELD_CHANGED:
            case Triggers::THRESHOLD:
                if ($old === null) {
                    return [null, null, null];
                }

                $details = $this->triggers->matchesChange($trigger, 'updated', $old, $values, $type, $timezone);

                return [$details !== null, $details, null];

            case Triggers::DATE:
                $field = $type->field((string) ($trigger['field'] ?? ''));

                if ($field === null) {
                    return [false, null, null];
                }

                $target = Triggers::targetDate($trigger, CarbonImmutable::now($timezone)->toDateString());

                return [$this->change->equals($field, $values[$field->name] ?? null, $target, $timezone), ['date' => $target], null];

            case Triggers::SCHEDULE:
                $zone = $this->timezones->forCompany($companyId) ?? 'UTC';

                return [null, null, ScheduleRecurrence::next($trigger, CarbonImmutable::now(), $zone)->toIso8601ZuluString()];

            default:
                return [null, null, null];
        }
    }
}
