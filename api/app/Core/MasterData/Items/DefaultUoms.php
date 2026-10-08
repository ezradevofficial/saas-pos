<?php

namespace App\Core\MasterData\Items;

/**
 * MD-02: the units of measure every tenant starts with, named in English
 * and French from the translation files (`core.uom.defaults.{code}`).
 * Seeding is idempotent: a code the tenant has ever had, archived
 * included, is left alone, so a unit the tenant archived stays archived.
 */
class DefaultUoms
{
    /** code => kind */
    public const UNITS = [
        'EA' => 'count',
        'KG' => 'weight',
        'G' => 'weight',
        'L' => 'volume',
        'ML' => 'volume',
        'M' => 'length',
        'BOX' => 'count',
        'PACK' => 'count',
    ];

    /** Seed the current tenant's missing defaults; returns how many were created. */
    public function seed(): int
    {
        $existing = Uom::query()->pluck('code')->map(fn ($code) => strtoupper((string) $code))->all();
        $created = 0;

        foreach (self::UNITS as $code => $kind) {
            if (in_array($code, $existing, true)) {
                continue;
            }

            Uom::create([
                'code' => $code,
                'name_en' => __("core.uom.defaults.{$code}", [], 'en'),
                'name_fr' => __("core.uom.defaults.{$code}", [], 'fr'),
                'kind' => $kind,
            ]);
            $created++;
        }

        return $created;
    }
}
