<?php

namespace Modules\FixedAssets\TaxBook;

use InvalidArgumentException;

/**
 * FA-20, CP-01, CP-02: the tax wear-and-tear classes of a country, read
 * from its pack (`api/country-packs/{CODE}/fixed_assets.json`): key,
 * method (`straight_line`, `reducing_balance`), annual rate (percent) and
 * the dates it is in force. A class with a null method or rate, or flagged
 * `needs_confirmation`, is unconfirmed: nothing is computed from it. Rates
 * are never invented; the packs ship unconfirmed (the owner's list is in
 * docs/modules/fixed-assets.md).
 */
class TaxClasses
{
    public const METHODS = ['straight_line', 'reducing_balance'];

    /** @var array<string, array<string, mixed>> */
    private array $read = [];

    /** @var array<string, array<string, mixed>> packs set by tests */
    private array $overrides = [];

    /** Tests only: use $pack for $country instead of its file. */
    public function override(string $country, array $pack): void
    {
        $this->overrides[$country] = $pack;
        unset($this->read[$country]);
    }

    /** @return list<array{key: string, method: ?string, rate: ?string, needs_confirmation: bool, effective_from: ?string, effective_to: ?string}> */
    public function for(?string $country): array
    {
        if ($country === null || preg_match('/^[A-Z]{2}\z/', $country) !== 1) {
            return [];
        }

        return $this->read[$country] ??= $this->load($country);
    }

    /** @return array{key: string, method: ?string, rate: ?string, needs_confirmation: bool, effective_from: ?string, effective_to: ?string}|null */
    public function find(?string $country, string $key): ?array
    {
        foreach ($this->for($country) as $class) {
            if ($class['key'] === $key) {
                return $class;
            }
        }

        return null;
    }

    /** Whether the class can be computed from: a known method and rate, confirmed. */
    public static function confirmed(?array $class): bool
    {
        return $class !== null && ! $class['needs_confirmation'] && $class['rate'] !== null && in_array($class['method'], self::METHODS, true);
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

        $file = base_path("country-packs/{$country}/fixed_assets.json");

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
