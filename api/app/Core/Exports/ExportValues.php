<?php

namespace App\Core\Exports;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use Carbon\CarbonImmutable;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Values of an exported file in the reader's language (EXP-01, L10N-03):
 * money with its code first ("KES 12,450.00", "CDF 135 000" in French),
 * dates and times in the record's company time zone (else the export's),
 * translated enums. Mirrors the web app's formatting (web/src/lib/format.js):
 * numbers as en-KE / fr-CD, dates as "7 Oct 2026, 14:05".
 */
final class ExportValues
{
    private const NUMBER_LOCALES = ['en' => 'en_KE', 'fr' => 'fr_CD'];

    private const DATE_LOCALES = ['en' => 'en_GB', 'fr' => 'fr'];

    /** @var array<string, int> */
    private array $decimals = [];

    private readonly string $groupMark;

    private readonly string $decimalMark;

    /** @param array<string, string> $companyTimezones company id => time zone */
    public function __construct(
        public readonly string $locale,
        public readonly string $timezone,
        private readonly array $companyTimezones,
        private readonly CurrencyDecimals $currencyDecimals,
    ) {
        $numbers = new NumberFormatter(self::NUMBER_LOCALES[$locale] ?? self::NUMBER_LOCALES['en'], NumberFormatter::DECIMAL);
        $this->groupMark = $numbers->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $this->decimalMark = $numbers->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }

    /** "KES 12,450.00" from Money or `{amount_minor, currency}` (as resources render money), or null. */
    public function money(Money|array|null $money): ?string
    {
        if ($money instanceof Money) {
            $money = $money->jsonSerialize();
        }

        if ($money === null || ! isset($money['amount_minor'], $money['currency'])) {
            return null;
        }

        $currency = (string) $money['currency'];

        return $currency.' '.$this->minor((string) $money['amount_minor'], $this->decimals[$currency] ??= $this->currencyDecimals->for($currency));
    }

    /** Minor units as a grouped decimal without floats: ("1245000", 2) => "12,450.00". */
    public function minor(string $minor, int $decimals): string
    {
        $negative = str_starts_with($minor, '-');
        $digits = str_pad(ltrim($minor, '-'), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = $decimals > 0 ? substr($digits, 0, -$decimals) : $digits;
        $text = $this->group(ltrim($whole, '0') ?: '0');

        if ($decimals > 0) {
            $text .= $this->decimalMark.substr($digits, -$decimals);
        }

        return ($negative ? '-' : '').$text;
    }

    /**
     * A decimal string (a rate, a percentage) without floats, grouped, its
     * trailing zeros dropped: "2850.50000000" => "2,850.5" / "2 850,5".
     */
    public function decimal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        [$whole, $fraction] = explode('.', $value, 2) + [1 => ''];
        $fraction = rtrim($fraction, '0');
        $text = $this->integer($whole === '' || $whole === '-' ? $whole.'0' : $whole);

        return $fraction === '' ? $text : $text.$this->decimalMark.$fraction;
    }

    /** A whole number with the language's grouping: 12,450 / 12 450. */
    public function integer(int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        $negative = str_starts_with($value, '-');

        return ($negative ? '-' : '').$this->group(ltrim($value, '-'));
    }

    /** "7 Oct 2026, 14:05" in the company's time zone (else the export's). */
    public function dateTime(?string $iso, ?string $companyId = null): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        $zone = ($companyId !== null ? ($this->companyTimezones[$companyId] ?? null) : null) ?: $this->timezone;

        return $this->formatDate(CarbonImmutable::parse($iso)->setTimezone($zone));
    }

    /** Now, for "Generated ..." on a PDF. */
    public function now(): string
    {
        return $this->formatDate(CarbonImmutable::now()->setTimezone($this->timezone));
    }

    /** A translated enum value: `{$prefix}.{$value}`, or the value itself when untranslated. */
    public function enum(string $prefix, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = "{$prefix}.{$value}";
        $text = __($key);

        return $text === $key ? $value : $text;
    }

    /** @param list<string|null>|null $values */
    public function join(?array $values): ?string
    {
        $values = array_values(array_filter($values ?? [], fn ($value) => $value !== null && $value !== ''));

        return $values === [] ? null : implode(', ', $values);
    }

    private function group(string $digits): string
    {
        $groups = [];

        while (strlen($digits) > 3) {
            array_unshift($groups, substr($digits, -3));
            $digits = substr($digits, 0, -3);
        }

        array_unshift($groups, $digits);

        return implode($this->groupMark, $groups);
    }

    private function formatDate(CarbonImmutable $date): string
    {
        $formatter = new IntlDateFormatter(
            self::DATE_LOCALES[$this->locale] ?? self::DATE_LOCALES['en'],
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            IntlDateFormatter::GREGORIAN,
            'd MMM y, HH:mm',
        );

        return (string) $formatter->format($date);
    }
}
