<?php

namespace Modules\Payroll\Statutory;

use Carbon\CarbonImmutable;

/**
 * CP-01, CP-02, PAY-07: Payroll's country-pack data
 * (`api/country-packs/{CODE}/payroll.json`): the statutory components with
 * their effective-dated values, the return types and the payment file
 * layouts. The packs ship structure only: every figure is null ("rate
 * needed") until the owner confirms it, and a run is refused while one it
 * needs is missing (MissingValues, PAY-08).
 *
 * Tests inject fixture packs with fake(); fixture rates never reach the
 * pack files.
 */
class PayrollPack
{
    /** @var array<string, ?array<string, mixed>> */
    private array $packs = [];

    /** @var array<string, ?array<string, mixed>> country => a test's pack */
    private array $fakes = [];

    /** The pack of $country, or null when the country has none. */
    public function of(?string $country): ?array
    {
        $code = strtoupper((string) $country);

        if (array_key_exists($code, $this->fakes)) {
            return $this->fakes[$code];
        }

        if (array_key_exists($code, $this->packs)) {
            return $this->packs[$code];
        }

        $file = base_path("country-packs/{$code}/payroll.json");

        return $this->packs[$code] = preg_match('/^[A-Z]{2}\z/', $code) === 1 && is_file($file)
            ? json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR)
            : null;
    }

    /** Tests only: use $pack for $country (null: the country has no pack). */
    public function fake(string $country, ?array $pack): void
    {
        $this->fakes[strtoupper($country)] = $pack;
    }

    /** The pack currency of $country (statutory figures are in it). */
    public function currency(?string $country): ?string
    {
        return $this->of($country)['currency'] ?? null;
    }

    /**
     * The components of $pack with the period in force on $on (`values`,
     * null when none is).
     *
     * @return list<array{code: string, kind: string, method: string, base: ?string, applies_to: ?string, when: ?string, required: bool, values: ?array<string, mixed>}>
     */
    public static function inForce(array $pack, CarbonImmutable $on): array
    {
        $date = $on->toDateString();
        $components = [];

        foreach ($pack['components'] ?? [] as $component) {
            $values = null;

            foreach ($component['periods'] ?? [] as $period) {
                if (($period['effective_from'] ?? '9999-12-31') <= $date && (($period['effective_to'] ?? null) === null || $period['effective_to'] >= $date)) {
                    $values = $period;
                }
            }

            $components[] = [
                'code' => (string) $component['code'],
                'kind' => (string) $component['kind'],
                'method' => (string) $component['method'],
                'base' => $component['base'] ?? null,
                'applies_to' => $component['applies_to'] ?? null,
                'when' => $component['when'] ?? null,
                'required' => (bool) ($component['required'] ?? true),
                'values' => $values,
            ];
        }

        return $components;
    }

    /** @return list<array<string, mixed>> the pack's return types */
    public function returnTypes(?string $country): array
    {
        return array_values($this->of($country)['returns'] ?? []);
    }

    /** @return array<string, mixed>|null a payment file layout of the pack */
    public function paymentFormat(?string $country, string $key): ?array
    {
        return $this->of($country)['payment_formats'][$key] ?? null;
    }

    /** The en/fr label of a pack component or return type. */
    public static function label(string $kind, string $code): string
    {
        $key = "payroll.pack.{$kind}.{$code}";
        $label = __($key);

        return $label === $key ? $code : $label;
    }
}
