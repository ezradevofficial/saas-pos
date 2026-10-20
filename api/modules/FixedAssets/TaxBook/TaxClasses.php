<?php

namespace Modules\FixedAssets\TaxBook;

use App\Core\CountryPacks\PackDocuments;
use InvalidArgumentException;

/**
 * FA-20, CP-01, CP-02: the tax wear-and-tear classes of a country, read
 * from its pack (`api/country-packs/{CODE}/fixed_assets.json`): key,
 * method (`straight_line`, `reducing_balance`), annual rate (percent) and
 * the dates it is in force; a key may have several rows, one per period
 * (review #11). A class with a null method or rate, or flagged
 * `needs_confirmation`, is unconfirmed: nothing is computed from it. The
 * pack's `acquisition_rule` says how the year an asset enters service
 * counts (`full_year`, or `pro_rata_months`: its months in service over
 * 12); it too must be confirmed. Rates and rules are never invented; the
 * packs ship unconfirmed (the owner's list is in
 * docs/modules/fixed-assets.md).
 */
class TaxClasses
{
    public const METHODS = ['straight_line', 'reducing_balance'];

    public const ACQUISITION_RULES = ['full_year', 'pro_rata_months'];

    /** @var array<string, array<string, mixed>> */
    private array $read = [];

    /** @var array<string, array<string, mixed>> packs set by tests */
    private array $overrides = [];

    /** Tests only: use $pack for $country instead of its file. */
    public function override(string $country, array $pack): void
    {
        $this->overrides[$country] = $pack;
        $this->read = [];
    }

    /** @return list<array{key: string, method: ?string, rate: ?string, needs_confirmation: bool, effective_from: ?string, effective_to: ?string}> */
    public function for(?string $country): array
    {
        if ($country === null || preg_match('/^[A-Z]{2}\z/', $country) !== 1) {
            return [];
        }

        // ADM-04d: a newly published release is picked up without a restart.
        $key = $country.'|'.(app(PackDocuments::class)->release($country, 'fixed_assets')?->id ?? 'file');

        return $this->read[$key] ??= $this->load($country);
    }

    /**
     * The row of class $key in force on $on (any row of it when $on is null:
     * whether the key exists).
     *
     * @return array{key: string, method: ?string, rate: ?string, needs_confirmation: bool, effective_from: ?string, effective_to: ?string}|null
     */
    public function find(?string $country, string $key, ?string $on = null): ?array
    {
        foreach ($this->for($country) as $class) {
            if ($class['key'] === $key && ($on === null
                || (($class['effective_from'] === null || $class['effective_from'] <= $on) && ($class['effective_to'] === null || $class['effective_to'] >= $on)))) {
                return $class;
            }
        }

        return null;
    }

    /** Whether every row of class $key can be computed from (and there is one). */
    public function keyConfirmed(?string $country, string $key): bool
    {
        $rows = array_filter($this->for($country), fn (array $class) => $class['key'] === $key);

        return $rows !== [] && array_filter($rows, fn (array $class) => ! self::confirmed($class)) === [];
    }

    /** Whether the class row can be computed from: a known method and rate, confirmed. */
    public static function confirmed(?array $class): bool
    {
        return $class !== null && ! $class['needs_confirmation'] && $class['rate'] !== null && in_array($class['method'], self::METHODS, true);
    }

    /** The confirmed year-of-acquisition rule of the pack (`full_year`, `pro_rata_months`), or null. */
    public function acquisitionRule(?string $country): ?string
    {
        if ($country === null || preg_match('/^[A-Z]{2}\z/', $country) !== 1) {
            return null;
        }

        $rule = (array) ($this->pack($country)['acquisition_rule'] ?? []);

        return ($rule['needs_confirmation'] ?? true) === false && in_array($rule['value'] ?? null, self::ACQUISITION_RULES, true) ? $rule['value'] : null;
    }

    /** @return list<string> what the pack's notes ask the owner to confirm */
    public function todo(?string $country): array
    {
        $pack = $this->pack((string) $country);

        return array_values(array_map('strval', (array) ($pack['todo'] ?? [])));
    }

    private function load(string $country): array
    {
        $pack = $this->pack($country);
        $classes = [];

        foreach ((array) ($pack['classes'] ?? []) as $row) {
            $key = (string) ($row['key'] ?? '');

            if (preg_match('/^[a-z][a-z0-9_]{0,59}\z/', $key) !== 1) {
                throw new InvalidArgumentException("Fixed asset tax class [{$key}] of {$country}: bad key.");
            }

            if (($row['method'] ?? null) !== null && ! in_array($row['method'], self::METHODS, true)) {
                throw new InvalidArgumentException("Fixed asset tax class {$key} of {$country}: unknown method.");
            }

            $classes[] = [
                'key' => $key,
                'method' => $row['method'] ?? null,
                'rate' => isset($row['rate']) ? (string) $row['rate'] : null,
                'needs_confirmation' => (bool) ($row['needs_confirmation'] ?? true),
                'effective_from' => $row['effective_from'] ?? null,
                'effective_to' => $row['effective_to'] ?? null,
            ];
        }

        return $classes;
    }

    private function pack(string $country): array
    {
        if (isset($this->overrides[$country])) {
            return $this->overrides[$country];
        }

        // ADM-04d (ADR 022): a published release in force replaces the shipped file.
        if (($release = app(PackDocuments::class)->release($country, 'fixed_assets')) !== null) {
            return $release->content;
        }

        $file = base_path("country-packs/{$country}/fixed_assets.json");

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
