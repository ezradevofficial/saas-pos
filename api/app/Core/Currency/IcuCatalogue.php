<?php

namespace App\Core\Currency;

use NumberFormatter;
use ResourceBundle;
use RuntimeException;

/**
 * Reads the ISO 4217 catalogue from the ICU data bundled with ext-intl
 * (CUR-01), so no list is maintained by hand:
 *
 * - codes: `ICUDATA-curr` locale bundle `en`, key `Currencies` (every
 *   code ICU names, historic ones included). Names are not stored; they
 *   come from ICU in the reader's locale (CurrencyNames);
 * - numeric codes: `ICUDATA` bundle `currencyNumericCodes`;
 * - decimals: NumberFormatter's fraction digits for `en@currency=XXX`,
 *   except the project overrides below;
 * - `active_in_iso`: the code is the current, legal-tender currency of at
 *   least one territory in ICU's `supplementalData` CurrencyMap (an entry
 *   with no `to` date and not `tender: false`). Historic codes (ZWD, ZRN)
 *   and funds/metals (XAU, XXX) are therefore false.
 */
final class IcuCatalogue
{
    /** Decimals the project sets against ICU (CLAUDE.md, ADR 003). */
    public const OVERRIDES = ['CDF' => 0];

    /** @return list<array{code: string, numeric_code: ?int, default_decimals: int, active_in_iso: bool}> */
    public static function read(): array
    {
        $codes = array_keys(CurrencyNames::all('en'));

        if ($codes === []) {
            throw new RuntimeException('ICU has no currency names for [en].');
        }

        $numeric = self::numericCodes();
        $current = self::currentCodes();

        $rows = [];

        foreach ($codes as $code) {
            $rows[] = [
                'code' => $code,
                'numeric_code' => $numeric[$code] ?? null,
                'default_decimals' => self::OVERRIDES[$code] ?? self::fractionDigits($code),
                'active_in_iso' => isset($current[$code]),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /** @return array<string, int> */
    private static function numericCodes(): array
    {
        $map = self::bundle('currencyNumericCodes', 'ICUDATA', false)['codeMap'] ?? null;
        $codes = [];

        if ($map instanceof ResourceBundle) {
            foreach ($map as $code => $number) {
                $codes[$code] = (int) $number;
            }
        }

        return $codes;
    }

    /** @return array<string, true> codes currently in use somewhere */
    private static function currentCodes(): array
    {
        $map = self::bundle('supplementalData', 'ICUDATA-curr', false)['CurrencyMap'] ?? null;

        if (! $map instanceof ResourceBundle) {
            throw new RuntimeException('ICU has no currency map.');
        }

        $current = [];
        foreach ($map as $territory) {
            foreach ($territory as $entry) {
                $fields = [];
                foreach ($entry as $key => $value) {
                    $fields[$key] = $value;
                }

                if (! isset($fields['to']) && ($fields['tender'] ?? 'true') !== 'false' && is_string($fields['id'] ?? null)) {
                    $current[$fields['id']] = true;
                }
            }
        }

        return $current;
    }

    private static function fractionDigits(string $code): int
    {
        $formatter = new NumberFormatter('en@currency='.$code, NumberFormatter::CURRENCY);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    private static function bundle(string $locale, string $bundle, bool $fallback = true): ResourceBundle
    {
        $resource = ResourceBundle::create($locale, $bundle, $fallback);

        if ($resource === null) {
            throw new RuntimeException("ICU bundle [{$bundle}/{$locale}] is missing: ".intl_get_error_message());
        }

        return $resource;
    }
}
