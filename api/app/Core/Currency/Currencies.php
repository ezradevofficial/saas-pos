<?php

namespace App\Core\Currency;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The global currency catalogue (CUR-01), cached: it changes only when
 * `currencies:sync` runs, which forgets the cache. Global data, so one key
 * for every tenant.
 */
class Currencies
{
    public const CACHE_KEY = 'currency.catalogue';

    /** @var Collection<string, array{code: string, numeric_code: ?int, name_en: string, name_fr: string, default_decimals: int, active_in_iso: bool}>|null */
    private ?Collection $loaded = null;

    /** @return Collection<string, array{code: string, numeric_code: ?int, name_en: string, name_fr: string, default_decimals: int, active_in_iso: bool}> keyed by code */
    public function all(): Collection
    {
        return $this->loaded ??= collect(Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('currencies')
            ->orderBy('code')
            ->get(['code', 'numeric_code', 'name_en', 'name_fr', 'default_decimals', 'active_in_iso'])
            ->map(fn (object $row) => [
                'code' => $row->code,
                'numeric_code' => $row->numeric_code === null ? null : (int) $row->numeric_code,
                'name_en' => $row->name_en,
                'name_fr' => $row->name_fr,
                'default_decimals' => (int) $row->default_decimals,
                'active_in_iso' => (bool) $row->active_in_iso,
            ])
            ->keyBy('code')
            ->all()));
    }

    /** @return array{code: string, numeric_code: ?int, name_en: string, name_fr: string, default_decimals: int, active_in_iso: bool}|null */
    public function find(string $code): ?array
    {
        return $this->all()->get($code);
    }

    /** The currency's name in $locale (French or English). */
    public function name(string $code, ?string $locale = null): string
    {
        $currency = $this->find($code);

        if ($currency === null) {
            return $code;
        }

        return ($locale ?? app()->getLocale()) === 'fr' ? $currency['name_fr'] : $currency['name_en'];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }
}
