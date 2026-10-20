<?php

namespace Modules\Payroll\Statutory;

use App\Core\CountryPacks\PackDocuments;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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
    /** Review fix 5: environments where PAYROLL_PACK_DIR (uncommitted test packs) is honoured. */
    public const OVERRIDE_ENVIRONMENTS = ['local', 'testing', 'staging'];

    /**
     * Review fix 11: parsed packs, kept while their file is unchanged (its
     * path, modification time and size), so a long-running worker picks up
     * a pack update without a restart.
     *
     * @var array<string, array{key: string, pack: ?array<string, mixed>, source: array<string, ?string>}>
     */
    private array $packs = [];

    /** @var array<string, ?array<string, mixed>> country => a test's pack */
    private array $fakes = [];

    /** The pack of $country, or null when the country has none. */
    public function of(?string $country, ?CarbonInterface $on = null): ?array
    {
        return $this->load($country, $on)['pack'];
    }

    /**
     * Review fix 5: where $country's pack was read from (`shipped`,
     * `override`, `release` (ADM-04) or `test`), its file and the sha256 of its content, stamped
     * on each run it calculates.
     *
     * @return array{country: string, source: string, file: ?string, sha256: ?string}
     */
    public function source(?string $country, ?CarbonInterface $on = null): array
    {
        return $this->load($country, $on)['source'];
    }

    /** @return array{pack: ?array<string, mixed>, source: array<string, ?string>} */
    private function load(?string $country, ?CarbonInterface $on = null): array
    {
        $code = strtoupper((string) $country);

        if (array_key_exists($code, $this->fakes)) {
            $pack = $this->fakes[$code];

            return ['pack' => $pack, 'source' => ['country' => $code, 'source' => 'test', 'file' => null, 'sha256' => $pack === null ? null : hash('sha256', json_encode($pack))]];
        }

        $override = config('payroll.pack_dir');
        $overridden = is_string($override) && $override !== '' && app()->environment(self::OVERRIDE_ENVIRONMENTS);

        // ADM-04d (ADR 022): a published release in force replaces the shipped file; a run
        // (re)calculated for a past period reads the release in force on its pay date (review L7).
        $release = $overridden || preg_match('/^[A-Z]{2}\z/', $code) !== 1 ? null : app(PackDocuments::class)->release($code, 'payroll', $on);

        if ($release !== null) {
            $key = 'release|'.$release->id;

            if (($this->packs[$code]['key'] ?? null) === $key) {
                return $this->packs[$code];
            }

            return $this->packs[$code] = [
                'key' => $key,
                'pack' => $release->content,
                'source' => ['country' => $code, 'source' => 'release', 'file' => null, 'sha256' => $release->content_hash, 'version' => $release->version],
            ];
        }
        $dir = $overridden ? rtrim($override, '/') : base_path('country-packs');
        $file = preg_match('/^[A-Z]{2}\z/', $code) === 1 ? "{$dir}/{$code}/payroll.json" : null;

        clearstatcache(true, (string) $file);
        $exists = $file !== null && is_file($file);
        $key = $exists ? $file.'|'.filemtime($file).'|'.filesize($file) : 'none|'.$file;

        if (($this->packs[$code]['key'] ?? null) === $key) {
            return $this->packs[$code];
        }

        $content = $exists ? (string) file_get_contents($file) : null;

        return $this->packs[$code] = [
            'key' => $key,
            'pack' => $content === null ? null : json_decode($content, true, 32, JSON_THROW_ON_ERROR),
            'source' => ['country' => $code, 'source' => $overridden ? 'override' : 'shipped', 'file' => $exists ? $file : null, 'sha256' => $content === null ? null : hash('sha256', $content)],
        ];
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
            $periods = array_values($component['periods'] ?? []);

            foreach ($periods as $period) {
                if (($period['effective_from'] ?? '9999-12-31') <= $date && (($period['effective_to'] ?? null) === null || $period['effective_to'] >= $date)) {
                    $values = $period;
                }
            }

            // Review fix 11: two periods of one component covering the same day are an error of the pack.
            $overlapping = false;

            foreach ($periods as $i => $a) {
                foreach (array_slice($periods, $i + 1) as $b) {
                    $aEnd = $a['effective_to'] ?? '9999-12-31';
                    $bEnd = $b['effective_to'] ?? '9999-12-31';

                    if (($a['effective_from'] ?? '') <= $bEnd && ($b['effective_from'] ?? '') <= $aEnd) {
                        $overlapping = true;
                    }
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
                'overlapping' => $overlapping,
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
