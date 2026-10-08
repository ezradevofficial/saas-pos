<?php

namespace App\Core\CountryPacks;

use App\Core\Localisation\Http\SetLocale;
use Illuminate\Support\Facades\Lang;

/**
 * Country-pack labels are reference data we ship, translated through
 * `lang/{locale}/country_packs.php` keyed by pack and code
 * (`country_packs.KE.VAT_STD`, the pack's own name under `KE.name`), never
 * stored in the pack tables (CP-01, platform-core-spec Conventions; ADR 007).
 * Adding a language adds a translation file, not a column. A label missing
 * from the files falls back to the code.
 */
final class PackLabels
{
    public static function pack(string $pack, ?string $locale = null): string
    {
        return self::label("{$pack}.name", $locale) ?? $pack;
    }

    public static function taxCode(string $pack, string $code, ?string $locale = null): string
    {
        return self::label("{$pack}.{$code}", $locale) ?? $code;
    }

    /**
     * The translation keys the pack needs that a supported locale lacks,
     * as "fr: country_packs.KE.VAT_STD".
     *
     * @return list<string>
     */
    public static function missing(PackFile $file): array
    {
        $keys = ["{$file->code()}.name"];

        foreach (array_unique(array_column($file->taxCodes(), 'code')) as $code) {
            $keys[] = "{$file->code()}.{$code}";
        }

        $missing = [];

        foreach (SetLocale::SUPPORTED as $locale) {
            foreach ($keys as $key) {
                if (self::label($key, $locale) === null) {
                    $missing[] = "{$locale}: country_packs.{$key}";
                }
            }
        }

        return $missing;
    }

    private static function label(string $key, ?string $locale): ?string
    {
        $locale ??= app()->getLocale();

        return Lang::has("country_packs.{$key}", $locale, false) ? (string) __("country_packs.{$key}", [], $locale) : null;
    }
}
