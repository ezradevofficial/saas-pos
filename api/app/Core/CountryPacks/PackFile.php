<?php

namespace App\Core\CountryPacks;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Taxes\TaxCode;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * A country pack data file (`country-packs/{CODE}/pack.json`, CP-01):
 * tax code structure with effective-dated rates. Validated before it is
 * published. Labels are not part of the pack: they live in translation
 * files keyed by pack and code (PackLabels). A rate is a percentage given as a string ("12.5") or an
 * integer, never a JSON float (floats lose precision), or null when no
 * figure is confirmed; a null rate must say `needs_confirmation` (exempt
 * codes have no rate; zero-rated codes are 0 by definition). A code's
 * periods never overlap, at most one is open-ended, and all have the same
 * kind.
 */
final class PackFile
{
    /**
     * @param  array<string, mixed>  $data  the validated content, rates normalised to 4 decimals
     */
    private function __construct(public readonly array $data) {}

    public static function path(string $code): string
    {
        return base_path('country-packs/'.$code.'/pack.json');
    }

    public static function read(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Pack file [{$path}] does not exist.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException("Pack file [{$path}] is not valid JSON: {$e->getMessage()}");
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $validator = Validator::make($data, [
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}\z/'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'sources' => ['present', 'array'],
            'sources.*' => ['string'],
            'todo' => ['present', 'array'],
            'todo.*' => ['string'],
            'tax_codes' => ['required', 'array', 'min:1'],
            'tax_codes.*.code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+\z/'],
            'tax_codes.*.kind' => ['required', 'string', 'in:'.implode(',', TaxCode::KINDS)],
            'tax_codes.*.rate' => ['present', 'nullable'],
            'tax_codes.*.needs_confirmation' => ['required', 'boolean'],
            'tax_codes.*.effective_from' => ['required', 'date_format:Y-m-d'],
            'tax_codes.*.effective_to' => ['present', 'nullable', 'date_format:Y-m-d'],
            'tax_codes.*.fiscal_code' => ['present', 'nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid pack file: '.implode(' ', $validator->errors()->all()));
        }

        // Labels are translated in lang/{locale}/country_packs.php (PackLabels), never carried in the pack.
        foreach ([array_keys($data), ...array_map(fn ($row) => is_array($row) ? array_keys($row) : [], $data['tax_codes'])] as $keys) {
            foreach ($keys as $key) {
                if (is_string($key) && preg_match('/^(name|label)/', $key) === 1) {
                    throw new InvalidArgumentException("Invalid pack file: [{$key}] is not allowed; labels live in lang/{locale}/country_packs.php.");
                }
            }
        }

        $seen = [];

        foreach ($data['tax_codes'] as $index => $row) {
            $where = "tax_codes.{$index} ({$row['code']})";
            $rate = $row['rate'];

            if (! is_bool($row['needs_confirmation'])) {
                throw new InvalidArgumentException("{$where}: needs_confirmation must be true or false.");
            }

            if ($rate !== null) {
                if (! (is_int($rate) || is_string($rate)) || preg_match('/^\d{1,3}(\.\d{1,4})?\z/', (string) $rate) !== 1 || BigDecimal::of((string) $rate)->isGreaterThan(100)) {
                    throw new InvalidArgumentException("{$where}: rate must be a percentage between 0 and 100 with at most 4 decimals, as a string or an integer (never a JSON float), or null.");
                }

                $data['tax_codes'][$index]['rate'] = TaxCode::normaliseRate((string) $rate);
            }

            match (true) {
                $row['kind'] === 'exempt' && $rate !== null => throw new InvalidArgumentException("{$where}: an exempt code has no rate."),
                $row['kind'] === 'zero_rated' && ($rate === null || ! BigDecimal::of((string) $rate)->isZero()) => throw new InvalidArgumentException("{$where}: a zero-rated code has the rate 0."),
                $rate === null && $row['kind'] !== 'exempt' && $row['needs_confirmation'] !== true => throw new InvalidArgumentException("{$where}: a null rate needs needs_confirmation true; never invent a rate."),
                $row['effective_to'] !== null && $row['effective_to'] < $row['effective_from'] => throw new InvalidArgumentException("{$where}: effective_to is before effective_from."),
                default => null,
            };

            $key = $row['code'].'@'.$row['effective_from'];

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("{$where}: the code appears twice from the same date.");
            }

            $seen[$key] = true;
        }

        self::checkPeriods($data['tax_codes']);

        return new self($data);
    }

    /**
     * Each code's periods: same kind, no overlap (dates inclusive), at most
     * one open-ended (which is then the latest).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function checkPeriods(array $rows): void
    {
        $byCode = [];

        foreach ($rows as $row) {
            $byCode[$row['code']][] = $row;
        }

        foreach ($byCode as $code => $periods) {
            if (count(array_unique(array_column($periods, 'kind'))) > 1) {
                throw new InvalidArgumentException("{$code}: every period of a code has the same kind.");
            }

            if (count(array_filter($periods, fn (array $row) => $row['effective_to'] === null)) > 1) {
                throw new InvalidArgumentException("{$code}: at most one period is open-ended.");
            }

            usort($periods, fn (array $a, array $b) => strcmp($a['effective_from'], $b['effective_from']));

            for ($i = 1; $i < count($periods); $i++) {
                $previous = $periods[$i - 1];

                if ($previous['effective_to'] === null || $previous['effective_to'] >= $periods[$i]['effective_from']) {
                    throw new InvalidArgumentException("{$code}: the period from {$previous['effective_from']} overlaps the period from {$periods[$i]['effective_from']}.");
                }
            }
        }
    }

    public function code(): string
    {
        return $this->data['code'];
    }

    /** sha256 of the canonical JSON (keys sorted), so formatting changes do not make a new version. */
    public function hash(): string
    {
        return hash('sha256', Auditor::canonicalJson($this->data));
    }

    /** @return list<array<string, mixed>> */
    public function taxCodes(): array
    {
        return array_values($this->data['tax_codes']);
    }
}
