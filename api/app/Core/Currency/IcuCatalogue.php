<?php

namespace App\Core\Currency;

use NumberFormatter;
use ResourceBundle;
use RuntimeException;

/**
 * Reads the ISO 4217 catalogue from the ICU data bundled with ext-intl
 * (CUR-01), so no list is maintained by hand:
 *
 * - codes and names: `ICUDATA-curr` locale bundles `en` and `fr`, key
 *   `Currencies` (every code ICU names, historic ones included);
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

    /** @return list<array{code: string, numeric_code: ?int, name_en: string, name_fr: string, default_decimals: int, active_in_iso: bool}> */
    public static function read(): array
    {
        $en = self::names('en');
        $fr = self::names('fr');
        $numeric = self::numericCodes();
        $current = self::currentCodes();

        $rows = [];

        foreach ($en as $code => $name) {
            $rows[] = [
                'code' => $code,
                'numeric_code' => $numeric[$code] ?? null,
                'name_en' => $name,
                'name_fr' => $fr[$code] ?? $name,
                'default_decimals' => self::OVERRIDES[$code] ?? self::fractionDigits($code),
                'active_in_iso' => isset($current[$code]),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /** @return array<string, string> code => display name */
    private static function names(string $locale): array
    {
        $currencies = self::bundle($locale, 'ICUDATA-curr')['Currencies'] ?? null;

        if (! $currencies instanceof ResourceBundle) {
            throw new RuntimeException("ICU has no currency names for [{$locale}].");
        }

        $names = [];
        foreach ($currencies as $code => $entry) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1 && $entry instanceof ResourceBundle) {
                $names[$code] = (string) $entry[1];
            }
        }

        return $names;
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
