<?php

namespace App\Core\Imports;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\CustomFields\CustomFieldDefinition;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * IMP-01: reads one spreadsheet cell as its column's type. Cells come as
 * text (CSV, XLSX text) or as numbers, dates and booleans (XLSX). Returns
 * null for an empty cell, the value, or an ImportCellError.
 *
 * - Numbers: digits with `.` or `,` as the decimal separator; spaces
 *   (thousands) are ignored. Never a float: XLSX numbers become decimal
 *   strings.
 * - Dates: XLSX dates, `YYYY-MM-DD`, `DD/MM/YYYY` or `DD.MM.YYYY`.
 * - Yes/no: yes, no, true, false, 1, 0, oui, non.
 * - Lists: values separated by `;` or `,` (or new lines).
 * - Enums: the value or its label in English or French, ignoring case
 *   and accents.
 * - Custom fields (CF-03) are brought to the input CustomFieldValidator
 *   takes: money as "KES 12.50", options by value or label, lookups by id.
 */
class ImportCells
{
    /** The longest cell read; a longer one is a row error (`too_long`). */
    public const MAX_CELL = 10000;

    public function __construct(private readonly CurrencyDecimals $decimals) {}

    public function read(ImportColumn $column, mixed $raw, ?CustomFieldDefinition $field = null): mixed
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (is_string($raw) && mb_strlen($raw) > self::MAX_CELL) {
            return new ImportCellError('too_long');
        }

        if ($field !== null) {
            return $this->custom($field, $raw);
        }

        return match ($column->type) {
            'integer' => $this->integer($raw),
            'decimal' => $this->decimal($raw),
            'date' => $this->date($raw),
            'boolean' => $this->boolean($raw),
            'list' => $this->list($raw),
            'enum' => $this->enum($column, $raw),
            default => $this->text($raw),
        };
    }

    /** The cell as the text a person typed, for files written back (results, errors). */
    public static function display(mixed $raw): string
    {
        return match (true) {
            $raw === null => '',
            $raw instanceof DateTimeInterface => $raw->format('Y-m-d'),
            is_bool($raw) => $raw ? 'TRUE' : 'FALSE',
            is_float($raw) => self::floatText($raw),
            default => (string) $raw,
        };
    }

    public function text(mixed $raw): string
    {
        return trim(self::display($raw));
    }

    public function integer(mixed $raw): string|ImportCellError
    {
        $value = $this->decimal($raw);

        if ($value instanceof ImportCellError || preg_match('/^-?\d+\z/', $value) !== 1) {
            return new ImportCellError('integer');
        }

        return $value;
    }

    public function decimal(mixed $raw): string|ImportCellError
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            return self::floatText($raw);
        }

        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', self::display($raw));

        if (str_contains($text, ',') && str_contains($text, '.')) {
            // The later separator is the decimal one; the other groups thousands.
            $text = strrpos($text, ',') > strrpos($text, '.') ? str_replace(['.', ','], ['', '.'], $text) : str_replace(',', '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }

        return preg_match('/^-?\d+(\.\d+)?\z/', $text) === 1 ? $text : new ImportCellError('decimal');
    }

    public function date(mixed $raw): string|ImportCellError
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $text = trim(self::display($raw));

        foreach (['Y-m-d' => '/^\d{4}-\d{1,2}-\d{1,2}\z/', 'd/m/Y' => '/^\d{1,2}\/\d{1,2}\/\d{4}\z/', 'd.m.Y' => '/^\d{1,2}\.\d{1,2}\.\d{4}\z/'] as $format => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                try {
                    $date = CarbonImmutable::createFromFormat('!'.$format, $text, 'UTC');
                } catch (Throwable) {
                    return new ImportCellError('date');
                }

                // Rolled-over dates (31/02) are refused.
                return $date !== null && $date->format($format) === $this->padded($text, $format) ? $date->format('Y-m-d') : new ImportCellError('date');
            }
        }

        return new ImportCellError('date');
    }

    public function boolean(mixed $raw): bool|ImportCellError
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return match (Str::lower(Str::ascii(trim(self::display($raw))))) {
            'yes', 'y', 'true', '1', 'oui', 'o', 'vrai' => true,
            'no', 'n', 'false', '0', 'non', 'faux' => false,
            default => new ImportCellError('boolean'),
        };
    }

    /** @return list<string> */
    public function list(mixed $raw, string $separators = ";,\n"): array
    {
        $parts = preg_split('/['.preg_quote($separators, '/').']/', self::display($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    public function enum(ImportColumn $column, mixed $raw): string|ImportCellError
    {
        $wanted = self::key(self::display($raw));

        foreach ($column->options as $value) {
            $names = [$value];

            if ($column->optionsKey !== null) {
                $names[] = __("{$column->optionsKey}.{$value}", [], 'en');
                $names[] = __("{$column->optionsKey}.{$value}", [], 'fr');
            }

            foreach ($names as $name) {
                if (self::key($name) === $wanted) {
                    return $value;
                }
            }
        }

        return new ImportCellError('option');
    }

    /** CF-03: a custom field cell as CustomFieldValidator's input. */
    private function custom(CustomFieldDefinition $field, mixed $raw): mixed
    {
        return match ($field->type) {
            'number' => $this->decimal($raw),
            'money' => $this->money($raw),
            'date' => $this->date($raw),
            'datetime' => $raw instanceof DateTimeInterface ? CarbonImmutable::instance($raw)->utc()->format('Y-m-d\TH:i:s\Z') : $this->text($raw),
            'boolean' => $this->boolean($raw),
            'select' => $this->option($field, $this->text($raw)),
            'multi_select' => $this->options($field, $this->list($raw)),
            default => $this->text($raw),
        };
    }

    /** "KES 12.50", "12.50 KES" or "12,50 KES" as {amount_minor, currency}. */
    private function money(mixed $raw): array|ImportCellError
    {
        if (preg_match('/^\s*([A-Za-z]{3})?\s*([\d\s.,\x{00A0}\-]+?)\s*([A-Za-z]{3})?\s*\z/u', self::display($raw), $parts) !== 1) {
            return new ImportCellError('money');
        }

        $currency = strtoupper(($parts[1] ?? '') !== '' ? $parts[1] : ($parts[3] ?? ''));
        $amount = $this->decimal($parts[2]);

        if ($currency === '' || $amount instanceof ImportCellError) {
            return new ImportCellError('money');
        }

        try {
            return ['amount_minor' => Money::parse($amount, $currency, $this->decimals)->minor(), 'currency' => $currency];
        } catch (InvalidArgumentException) {
            return new ImportCellError('money');
        }
    }

    private function option(CustomFieldDefinition $field, string $text): string
    {
        foreach ($field->options as $option) {
            if (self::key($option['value']) === self::key($text) || self::key($option['label'] ?? '') === self::key($text)) {
                return $option['value'];
            }
        }

        // Left as typed: CustomFieldValidator names the error.
        return $text;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function options(CustomFieldDefinition $field, array $values): array
    {
        return array_map(fn (string $value) => $this->option($field, $value), $values);
    }

    /** Lower case, without accents, spaces or punctuation: how headers and labels are compared. */
    public static function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text)));
    }

    private static function floatText(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    private function padded(string $text, string $format): string
    {
        $parts = preg_split('/[-\/.]/', $text);
        $separator = $format[1];

        return $format === 'Y-m-d'
            ? sprintf('%04d-%02d-%02d', ...$parts)
            : sprintf('%02d%s%02d%s%04d', $parts[0], $separator, $parts[1], $separator, $parts[2]);
    }
}
