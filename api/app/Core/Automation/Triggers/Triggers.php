<?php

namespace App\Core\Automation\Triggers;

use App\Core\Automation\Capabilities\FindsDocumentsByDate;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Carbon\CarbonImmutable;

/**
 * AUTO-01: what starts a rule, as JSON in the rule's `trigger`:
 *
 *   {"type": "record_created"}
 *   {"type": "record_updated", "fields": ["total", "status"]}         fields optional: any of them changed
 *   {"type": "record_archived"}
 *   {"type": "field_changed", "field": "status", "from": "draft", "to": "approved"}   from and to optional
 *   {"type": "stage_entered", "stage": "review"}                        stage optional: any stage
 *   {"type": "stage_left", "stage": "review", "how": "completed"}       how optional: completed, returned, cancelled, joined
 *   {"type": "date", "field": "due_on", "days": 30, "when": "before"}   when: before, after, on (days 0)
 *   {"type": "threshold", "field": "quantity", "value": "10", "direction": "down"}   down: from at or above to below; up: the reverse
 *   {"type": "schedule", "every": "week", "days": ["mon"], "time": "08:00"}          see ScheduleRecurrence
 *
 * Record changes come from RecordChanged (modules raise it); stages from
 * the workflow engine's events; dates from a daily scan through the type's
 * FindsDocumentsByDate; schedules from a scan of rules due. Field values
 * compare as conditions do (FieldChange). The run log keeps which field
 * changed, never the values (people who read the log may not see them).
 */
class Triggers
{
    public const RECORD_CREATED = 'record_created';

    public const RECORD_UPDATED = 'record_updated';

    public const RECORD_ARCHIVED = 'record_archived';

    public const FIELD_CHANGED = 'field_changed';

    public const STAGE_ENTERED = 'stage_entered';

    public const STAGE_LEFT = 'stage_left';

    public const DATE = 'date';

    public const THRESHOLD = 'threshold';

    public const SCHEDULE = 'schedule';

    public const TYPES = [
        self::RECORD_CREATED, self::RECORD_UPDATED, self::RECORD_ARCHIVED, self::FIELD_CHANGED,
        self::STAGE_ENTERED, self::STAGE_LEFT, self::DATE, self::THRESHOLD, self::SCHEDULE,
    ];

    /** Triggers raised by RecordChanged. */
    public const RECORD_TRIGGERS = [self::RECORD_CREATED, self::RECORD_UPDATED, self::RECORD_ARCHIVED, self::FIELD_CHANGED, self::THRESHOLD];

    public const STAGE_HOW = ['completed', 'returned', 'cancelled', 'joined'];

    public const DATE_WHEN = ['before', 'after', 'on'];

    public const MAX_DAYS = 3650;

    private const STAGE = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly FieldChange $change,
        private readonly ConditionEvaluator $conditions,
    ) {}

    /**
     * Whether $type can ever fire a trigger of $triggerType: record
     * triggers need a type that raises record events
     * (DocumentType::raisesRecordEvents()), date triggers one searchable by
     * date (FindsDocumentsByDate), a threshold a number or money field.
     */
    public static function supports(string $triggerType, DocumentType $type): bool
    {
        if (in_array($triggerType, self::RECORD_TRIGGERS, true) && ! $type->raisesRecordEvents()) {
            return false;
        }

        return match ($triggerType) {
            self::DATE => $type instanceof FindsDocumentsByDate,
            self::THRESHOLD => array_filter($type->fields(), fn (FieldDefinition $f) => in_array($f->type, ['number', 'money'], true)) !== [],
            default => in_array($triggerType, self::TYPES, true),
        };
    }

    /** A trigger without a document: its actions cannot read or change one. */
    public static function hasDocument(array $trigger): bool
    {
        return ($trigger['type'] ?? null) !== self::SCHEDULE;
    }

    /**
     * Problems with a trigger, translated (empty when valid).
     *
     * @return list<string>
     */
    public function validate(mixed $trigger, DocumentType $type): array
    {
        if (! is_array($trigger) || array_is_list($trigger) || ! in_array($trigger['type'] ?? null, self::TYPES, true)) {
            return [__('automation.validation.trigger_type')];
        }

        // A type whose module never raises RecordChanged would never fire a record trigger.
        if (in_array($trigger['type'], self::RECORD_TRIGGERS, true) && ! $type->raisesRecordEvents()) {
            return [__('automation.validation.trigger_unsupported', ['type' => __($type->label())])];
        }

        $fields = $type->fieldsByName();
        $extra = fn (array $allowed) => array_diff(array_keys($trigger), ['type', ...$allowed]) === [] ? [] : [__('automation.validation.trigger_extra')];

        switch ($trigger['type']) {
            case self::RECORD_CREATED:
            case self::RECORD_ARCHIVED:
                return $extra([]);

            case self::RECORD_UPDATED:
                if (array_key_exists('fields', $trigger)) {
                    $names = $trigger['fields'];

                    if (! is_array($names) || ! array_is_list($names) || $names === [] || array_diff($names, array_keys($fields)) !== []) {
                        return [__('automation.validation.trigger_fields')];
                    }
                }

                return $extra(['fields']);

            case self::FIELD_CHANGED:
                $field = $this->field($trigger, $fields);

                if ($field === null) {
                    return [__('automation.validation.trigger_field')];
                }

                $problems = [];

                foreach (['from', 'to'] as $key) {
                    if (array_key_exists($key, $trigger) && $trigger[$key] !== null && ! $this->validValue($field, $trigger[$key], 'eq')) {
                        $problems[] = __('automation.validation.trigger_value', ['field' => $field->displayLabel()]);
                    }
                }

                return [...$problems, ...$extra(['field', 'from', 'to'])];

            case self::STAGE_ENTERED:
            case self::STAGE_LEFT:
                $problems = [];

                if (array_key_exists('stage', $trigger) && (! is_string($trigger['stage']) || preg_match(self::STAGE, $trigger['stage']) !== 1)) {
                    $problems[] = __('automation.validation.trigger_stage');
                }

                if ($trigger['type'] === self::STAGE_LEFT && array_key_exists('how', $trigger) && ! in_array($trigger['how'], self::STAGE_HOW, true)) {
                    $problems[] = __('automation.validation.trigger_how');
                }

                return [...$problems, ...$extra($trigger['type'] === self::STAGE_LEFT ? ['stage', 'how'] : ['stage'])];

            case self::DATE:
                if (! $type instanceof FindsDocumentsByDate) {
                    return [__('automation.validation.trigger_dates_unsupported')];
                }

                $field = $this->field($trigger, $fields);
                $problems = [];

                if ($field === null || $field->type !== 'date') {
                    $problems[] = __('automation.validation.trigger_date_field');
                }

                $when = $trigger['when'] ?? null;
                $days = $trigger['days'] ?? 0;

                if (! in_array($when, self::DATE_WHEN, true) || ! is_int($days) || $days < 0 || $days > self::MAX_DAYS || ($when === 'on' && $days !== 0)) {
                    $problems[] = __('automation.validation.trigger_days', ['max' => self::MAX_DAYS]);
                }

                return [...$problems, ...$extra(['field', 'days', 'when'])];

            case self::THRESHOLD:
                $field = $this->field($trigger, $fields);

                if ($field === null || ! in_array($field->type, ['number', 'money'], true)) {
                    return [__('automation.validation.trigger_threshold_field')];
                }

                $problems = [];

                if (! array_key_exists('value', $trigger) || ! $this->validValue($field, $trigger['value'], 'lt')) {
                    $problems[] = __('automation.validation.trigger_value', ['field' => $field->displayLabel()]);
                }

                if (! in_array($trigger['direction'] ?? null, ['up', 'down'], true)) {
                    $problems[] = __('automation.validation.trigger_direction');
                }

                return [...$problems, ...$extra(['field', 'value', 'direction'])];

            default: // schedule
                return array_map(fn (string $key) => __($key), ScheduleRecurrence::problems($trigger));
        }
    }

    /**
     * Whether a record change fires the trigger; the details for the run
     * log when it does, else null.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>|null
     */
    public function matchesChange(array $trigger, string $change, array $old, array $new, DocumentType $type, string $timezone = 'UTC'): ?array
    {
        $fields = $type->fieldsByName();

        switch ($trigger['type'] ?? null) {
            case self::RECORD_CREATED:
                return $change === 'created' ? ['change' => $change] : null;

            case self::RECORD_ARCHIVED:
                return $change === 'archived' ? ['change' => $change] : null;

            case self::RECORD_UPDATED:
                if ($change !== 'updated') {
                    return null;
                }

                $changed = [];

                foreach ($trigger['fields'] ?? array_keys($fields) as $name) {
                    if (isset($fields[$name]) && $this->change->changed($fields[$name], $old[$name] ?? null, $new[$name] ?? null, $timezone)) {
                        $changed[] = $name;
                    }
                }

                return isset($trigger['fields']) && $changed === [] ? null : ['change' => $change, 'fields' => $changed];

            case self::FIELD_CHANGED:
                $field = $fields[$trigger['field'] ?? ''] ?? null;

                if ($change !== 'updated' || $field === null) {
                    return null;
                }

                $before = $old[$field->name] ?? null;
                $after = $new[$field->name] ?? null;

                if (! $this->change->changed($field, $before, $after, $timezone)
                    || (array_key_exists('from', $trigger) && ! $this->change->equals($field, $before, $trigger['from'], $timezone))
                    || (array_key_exists('to', $trigger) && ! $this->change->equals($field, $after, $trigger['to'], $timezone))) {
                    return null;
                }

                return ['change' => $change, 'field' => $field->name];

            case self::THRESHOLD:
                $field = $fields[$trigger['field'] ?? ''] ?? null;

                if ($change !== 'updated' || $field === null
                    || ! $this->change->crossed($field, $trigger['value'] ?? null, (string) ($trigger['direction'] ?? ''), $old[$field->name] ?? null, $new[$field->name] ?? null)) {
                    return null;
                }

                return ['change' => $change, 'field' => $field->name, 'direction' => $trigger['direction']];

            default:
                return null;
        }
    }

    /**
     * Whether a workflow stage event fires the trigger.
     *
     * @param  'entered'|'left'  $event
     * @return array<string, mixed>|null
     */
    public function matchesStage(array $trigger, string $event, string $nodeId, ?string $how = null, ?string $outcome = null): ?array
    {
        $type = $trigger['type'] ?? null;

        if (($event === 'entered' && $type !== self::STAGE_ENTERED) || ($event === 'left' && $type !== self::STAGE_LEFT)) {
            return null;
        }

        if (isset($trigger['stage']) && $trigger['stage'] !== $nodeId) {
            return null;
        }

        if ($event === 'left' && isset($trigger['how']) && $trigger['how'] !== $how) {
            return null;
        }

        return array_filter(['stage' => $nodeId, 'how' => $how, 'outcome' => $outcome], fn ($v) => $v !== null);
    }

    /** The day a document's date field must fall on for a date trigger to fire on $today ('Y-m-d'). */
    public static function targetDate(array $trigger, string $today): string
    {
        $day = CarbonImmutable::parse($today);
        $days = (int) ($trigger['days'] ?? 0);

        return match ($trigger['when'] ?? 'on') {
            'before' => $day->addDays($days)->toDateString(),
            'after' => $day->subDays($days)->toDateString(),
            default => $today,
        };
    }

    /** The trigger in the reader's language, for the editor's summary and test mode. */
    public function describe(array $trigger, DocumentType $type): string
    {
        $fields = $type->fieldsByName();
        $label = fn (?string $name) => isset($fields[$name ?? '']) ? $fields[$name]->displayLabel() : (string) $name;
        $document = __($type->label());

        return match ($trigger['type'] ?? null) {
            self::RECORD_CREATED => __('automation.triggers.describe.record_created', ['document' => $document]),
            self::RECORD_UPDATED => isset($trigger['fields'])
                ? __('automation.triggers.describe.record_updated_fields', ['document' => $document, 'fields' => implode(', ', array_map($label, $trigger['fields']))])
                : __('automation.triggers.describe.record_updated', ['document' => $document]),
            self::RECORD_ARCHIVED => __('automation.triggers.describe.record_archived', ['document' => $document]),
            self::FIELD_CHANGED => __('automation.triggers.describe.field_changed', ['document' => $document, 'field' => $label($trigger['field'] ?? null)]),
            self::STAGE_ENTERED => isset($trigger['stage'])
                ? __('automation.triggers.describe.stage_entered', ['document' => $document, 'stage' => $trigger['stage']])
                : __('automation.triggers.describe.any_stage_entered', ['document' => $document]),
            self::STAGE_LEFT => isset($trigger['stage'])
                ? __('automation.triggers.describe.stage_left', ['document' => $document, 'stage' => $trigger['stage']])
                : __('automation.triggers.describe.any_stage_left', ['document' => $document]),
            self::DATE => __('automation.triggers.describe.date_'.($trigger['when'] ?? 'on'), ['days' => (int) ($trigger['days'] ?? 0), 'field' => $label($trigger['field'] ?? null)]),
            self::THRESHOLD => __('automation.triggers.describe.threshold_'.(($trigger['direction'] ?? 'down') === 'up' ? 'up' : 'down'), ['field' => $label($trigger['field'] ?? null)]),
            self::SCHEDULE => __('automation.triggers.describe.schedule_'.($trigger['every'] ?? 'day'), [
                'time' => $trigger['time'] ?? '',
                'days' => implode(', ', array_map(fn ($d) => __('automation.days.'.$d), (array) ($trigger['days'] ?? []))),
                'day' => (int) ($trigger['day'] ?? 1),
            ]),
            default => '',
        };
    }

    /** @param array<string, FieldDefinition> $fields */
    private function field(array $trigger, array $fields): ?FieldDefinition
    {
        return is_string($trigger['field'] ?? null) ? ($fields[$trigger['field']] ?? null) : null;
    }

    private function validValue(FieldDefinition $field, mixed $value, string $op): bool
    {
        return $this->conditions->validate(['field' => $field->name, 'op' => $op, 'value' => $value], [$field->name => $field]) === [];
    }
}
