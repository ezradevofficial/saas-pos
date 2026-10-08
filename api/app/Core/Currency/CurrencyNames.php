<?php

namespace App\Core\Currency;

use ResourceBundle;

/**
 * Currency names are reference data we ship, not business data: they are
 * never stored, but read from the ICU data bundled with ext-intl in the
 * reader's locale (CUR-01; platform-core-spec Conventions). Adding a
 * language adds no column.
 */
final class CurrencyNames
{
    /** @var array<string, array<string, string>> locale => code => name */
    private static array $names = [];

    /** The name of $code in $locale (the app locale by default); the code itself when ICU has none. */
    public static function for(string $code, ?string $locale = null): string
    {
        return self::all($locale)[$code] ?? $code;
    }

    /** @return array<string, string> code => display name, every code ICU names */
    public static function all(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return self::$names[$locale] ??= self::read($locale);
    }

    /** @return array<string, string> */
    private static function read(string $locale): array
    {
        $bundle = ResourceBundle::create($locale, 'ICUDATA-curr');
        $currencies = $bundle?->get('Currencies');
        $names = [];

        if ($currencies instanceof ResourceBundle) {
            foreach ($currencies as $code => $entry) {
                if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1 && $entry instanceof ResourceBundle) {
                    $names[$code] = (string) $entry[1];
                }
            }
        }

        return $names;
    }
}
