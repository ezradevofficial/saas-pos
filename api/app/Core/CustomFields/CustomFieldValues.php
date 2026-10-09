<?php

namespace App\Core\CustomFields;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * CF-01, CF-02: one value of a custom field, checked against its type and
 * settings and brought to its stored shape (CustomFieldTypes). Pure, apart
 * from the active currencies (money) and the file rows (file): lookups and
 * file ownership need the actor and are checked by CustomFieldValidator.
 */
class CustomFieldValues
{
    public function __construct(private readonly CurrencyDecimals $decimals) {}

    /**
     * The stored shape of $value, or a CustomFieldError naming the problem.
     * Null (and an empty string or list) means "no value".
     */
    public function normalise(CustomFieldDefinition $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field->type) {
            'text', 'long_text' => $this->text($field, $value),
            'number' => $this->number($field, $value),
            'money' => $this->money($value),
            'date' => $this->date($value),
            'datetime' => $this->dateTime($value),
            'boolean' => is_bool($value) ? $value : (in_array($value, [0, 1], true) ? $value === 1 : new CustomFieldError('boolean')),
            'select' => is_string($value) && in_array($value, $field->optionValues(), true) ? $value : new CustomFieldError('option'),
            'multi_select' => $this->options($field, $value),
            'file', 'lookup' => is_string($value) && Str::isUuid($value) ? strtolower($value) : new CustomFieldError($field->type),
            default => new CustomFieldError('readonly'),
        };
    }

    /**
     * The values formulas read, by key, for every active non-formula field:
     * numbers and money (in major units) as decimals, yes/no as booleans,
     * everything else as text.
     *
     * @param  iterable<CustomFieldDefinition>  $fields
     * @param  array<string, mixed>  $custom
     * @return array<string, BigDecimal|string|bool|null>
     */
    public function formulaInputs(iterable $fields, array $custom): array
    {
        $inputs = [];

        foreach ($fields as $field) {
            if ($field->type === 'formula') {
                continue;
            }

            $value = $custom[$field->key] ?? null;

            $inputs[$field->key] = match (true) {
                $value === null => null,
                $field->type === 'number' => BigDecimal::of((string) $value),
                $field->type === 'money' => BigDecimal::ofUnscaledValue((string) $value['amount_minor'], $this->decimals->for((string) $value['currency'])),
                $field->type === 'boolean' => (bool) $value,
                $field->type === 'multi_select' => implode(', ', (array) $value),
                default => (string) $value,
            };
        }

        // Money fields in different currencies can't be combined: they compute nothing.
        $currencies = [];
        foreach ($fields as $field) {
            if ($field->type === 'money' && isset($custom[$field->key]['currency'])) {
                $currencies[(string) $custom[$field->key]['currency']] = true;
            }
        }
        if (count($currencies) > 1) {
            foreach ($fields as $field) {
                if ($field->type === 'money') {
                    $inputs[$field->key] = null;
                }
            }
        }

        return $inputs;
    }

    private function text(CustomFieldDefinition $field, mixed $value): string|CustomFieldError
    {
        if (! is_string($value)) {
            return new CustomFieldError('text');
        }

        $value = $field->type === 'text' ? trim($value) : $value;
        $length = mb_strlen($value);
        $max = $field->type === 'text' ? CustomFieldTypes::MAX_TEXT : CustomFieldTypes::MAX_LONG_TEXT;

        if ($length > $max) {
            return new CustomFieldError('too_long', ['max' => $max]);
        }

        if ($field->min_value !== null && $length < (int) $field->bound('min')) {
            return new CustomFieldError('min_length', ['min' => $field->bound('min')]);
        }

        if ($field->max_value !== null && $length > (int) $field->bound('max')) {
            return new CustomFieldError('max_length', ['max' => $field->bound('max')]);
        }

        if ($field->pattern !== null && $field->pattern !== '' && self::matches($field->pattern, $value) !== true) {
            return new CustomFieldError('pattern');
        }

        return $value === '' ? new CustomFieldError('text') : $value;
    }

    /**
     * Whether $value matches the admin's $pattern as a whole (true), not
     * (false), or the pattern is unusable (null: invalid, or too costly).
     */
    public static function matches(string $pattern, string $value): ?bool
    {
        // A delimiter no admin pattern can contain, so "\/" and "/" mean what they say.
        if (str_contains($pattern, "\x01")) {
            return null;
        }
        $regex = "\x01^(?:".$pattern.")$\x01u";
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        try {
            $result = @preg_match($regex, $value);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        return $result === false ? null : $result === 1;
    }

    private function number(CustomFieldDefinition $field, mixed $value): string|CustomFieldError
    {
        // Never floats (ADR 003): an integer or a decimal string.
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match(CustomFieldTypes::NUMBER_PATTERN, trim($value)) !== 1) {
            return new CustomFieldError('number');
        }

        $number = BigDecimal::of(trim($value));

        if ($field->min_value !== null && $number->isLessThan($field->bound('min'))) {
            return new CustomFieldError('min', ['min' => $field->bound('min')]);
        }

        if ($field->max_value !== null && $number->isGreaterThan($field->bound('max'))) {
            return new CustomFieldError('max', ['max' => $field->bound('max')]);
        }

        return (string) $number->strippedOfTrailingZeros();
    }

    /** @return array{amount_minor: string, currency: string}|CustomFieldError */
    private function money(mixed $value): array|CustomFieldError
    {
        if (! is_array($value) || array_diff(array_keys($value), ['amount_minor', 'currency']) !== []) {
            return new CustomFieldError('money');
        }

        $amount = $value['amount_minor'] ?? null;
        $amount = is_int($amount) ? (string) $amount : $amount;
        $currency = $value['currency'] ?? null;

        if (! is_string($amount) || preg_match('/^-?\d{1,18}\z/', $amount) !== 1 || ! is_string($currency) || preg_match('/^[A-Z]{3}\z/', $currency) !== 1) {
            return new CustomFieldError('money');
        }

        if (! DB::connection(TenantContext::CONNECTION)->table('tenant_currencies')->where('code', $currency)->where('active', true)->exists()) {
            return new CustomFieldError('currency');
        }

        return ['amount_minor' => (string) BigDecimal::of($amount), 'currency' => $currency];
    }

    private function date(mixed $value): string|CustomFieldError
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return new CustomFieldError('date');
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : new CustomFieldError('date');
    }

    private function dateTime(mixed $value): string|CustomFieldError
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            return new CustomFieldError('datetime');
        }

        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return new CustomFieldError('datetime');
        }
    }

    /** @return list<string>|CustomFieldError */
    private function options(CustomFieldDefinition $field, mixed $value): array|CustomFieldError
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > CustomFieldTypes::MAX_OPTIONS) {
            return new CustomFieldError('options');
        }

        $allowed = $field->optionValues();

        foreach ($value as $entry) {
            if (! is_string($entry) || ! in_array($entry, $allowed, true)) {
                return new CustomFieldError('option');
            }
        }

        // Each once, in the options' order.
        return array_values(array_filter($allowed, fn (string $option) => in_array($option, $value, true)));
    }
}
