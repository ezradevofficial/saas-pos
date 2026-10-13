<?php

namespace App\Core\Billing\Catalogue\Models;

/** Catalogue names are written by platform staff in English and French (L10N-01). */
final class CatalogueText
{
    public const LOCALES = ['en', 'fr'];

    /** @param  array<string, string>|null  $names */
    public static function pick(?array $names, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($names[$locale] ?? $names['en'] ?? '');
    }
}
