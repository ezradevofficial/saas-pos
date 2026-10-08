<?php

namespace App\Core\Automation\Runtime;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Exports\ExportValues;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;

/**
 * Field values as text, and `{field}` placeholders filled from a document
 * (AUTO-03 notification text): money with its currency code first
 * ("KES 12,450.00"), dates as "7 Oct 2026", booleans as yes/no, in the
 * given language and time zone. A tenant's text is one text (NOT-03 owner
 * decision): the placeholders are filled once, in the organisation's
 * language, and the same text goes to everyone.
 */
class FieldText
{
    /** `{name}`: a field of the type, or document_type / rule_name. */
    public const PLACEHOLDER = '/\{([a-z][a-z0-9_]*)\}/';

    public const BUILT_IN = ['document_type', 'rule_name'];

    public function __construct(private readonly CurrencyDecimals $decimals) {}

    /**
     * Placeholders in $text that are neither a field of $type nor built in.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $text, DocumentType $type, bool $hasDocument = true): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);
        $known = [...self::BUILT_IN, ...($hasDocument ? array_keys($type->fieldsByName()) : [])];

        return array_values(array_unique(array_diff($matches[1], $known)));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $builtIn  document_type, rule_name
     */
    public function render(string $text, DocumentType $type, array $values, array $builtIn, string $locale, string $timezone): string
    {
        $fields = $type->fieldsByName();

        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($fields, $values, $builtIn, $locale, $timezone) {
            $name = $match[1];

            if (array_key_exists($name, $builtIn)) {
                return $builtIn[$name];
            }

            return isset($fields[$name]) ? $this->format($fields[$name], $values[$name] ?? null, $locale, $timezone) : $match[0];
        }, $text);
    }

    public function format(FieldDefinition $field, mixed $value, string $locale, string $timezone): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        $values = new ExportValues($locale, $timezone, [], $this->decimals);

        return match ($field->type) {
            'money' => $value instanceof Money || is_array($value) ? (string) $values->money($value) : '',
            'number' => (string) ($values->decimal(is_scalar($value) ? (string) $value : null) ?? ''),
            'date' => is_string($value) ? (string) (strlen($value) === 10 ? $values->date($value) : $values->dateTime($value)) : '',
            'boolean' => __('automation.values.'.($value ? 'yes' : 'no'), [], $locale),
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
