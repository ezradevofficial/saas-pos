<?php

namespace App\Core\Workflow\Conditions;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ExportValues;
use App\Core\Workflow\DocumentTypes\FieldDefinition;

/**
 * Turns failed comparisons into sentences in the reader's language, for
 * blocked moves (WF-04), dry runs and automation logs: "Total must be more
 * than KES 250,000.00; it is KES 120,000.00." Money keeps its currency
 * code first; dates read "7 Oct 2026".
 */
class ConditionDescriber
{
    public function __construct(private readonly CurrencyDecimals $decimals) {}

    /**
     * @param  array<string, FieldDefinition>  $fields
     * @return list<string>
     */
    public function reasons(ConditionResult $result, array $fields, string $timezone = 'UTC'): array
    {
        return array_values(array_unique(array_map(fn (ConditionCheck $check) => $this->describe($check, $fields, $timezone), $result->failures)));
    }

    /**
     * @param  array<string, FieldDefinition>  $fields
     * @param  string  $timezone  date-times are shown in it (the document's company's)
     */
    public function describe(ConditionCheck $check, array $fields, string $timezone = 'UTC'): string
    {
        $field = $fields[$check->field] ?? null;
        $label = $field === null ? $check->field : __($field->label);
        $values = new ExportValues(app()->getLocale(), $timezone, [], $this->decimals);

        if ($check->problem === 'currency_mismatch') {
            return __('workflow.conditions.problems.currency_mismatch', [
                'field' => $label,
                'currency' => $check->expected['currency'] ?? '',
                'actual_currency' => $check->actual['currency'] ?? '',
            ]);
        }

        if ($check->problem !== null || $field === null) {
            return __('workflow.conditions.problems.invalid_value', ['field' => $label]);
        }

        $expected = $check->other !== null
            ? __($fields[$check->other]->label ?? $check->other)
            : $this->format($field, $check->expected, $values);

        return __('workflow.conditions.failed.'.$check->op, [
            'field' => $label,
            'value' => $expected,
            'actual' => $this->format($field, $check->actual, $values),
        ]);
    }

    private function format(FieldDefinition $field, mixed $value, ExportValues $values): string
    {
        if ($value === null || $value === '' || $value === []) {
            return __('workflow.conditions.nothing');
        }

        if (is_array($value) && array_is_list($value)) {
            return implode(', ', array_map(fn ($v) => $this->format($field, $v, $values), $value));
        }

        return match ($field->type) {
            'money' => is_array($value) ? (string) $values->money($value) : (string) json_encode($value),
            'number' => (string) ($values->decimal(is_scalar($value) ? (string) $value : null) ?? ''),
            'date' => is_string($value) ? (string) (strlen($value) === 10 ? $values->date($value) : $values->dateTime($value)) : '',
            'boolean' => __('workflow.conditions.'.($value ? 'yes' : 'no')),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }
}
