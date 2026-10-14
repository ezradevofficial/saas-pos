<?php

namespace Modules\HR\Support;

use Carbon\CarbonImmutable;

/**
 * CP-01, CP-02: HR's country-pack data (`api/country-packs/{CODE}/hr.json`):
 * identifier types (HR-03) and statutory leave types (HR-12). Structure
 * only: a null pattern enforces no format, null days mean "Days needed".
 * Labels are translated reference data (`hr.pack.*`).
 */
class HrPack
{
    /** @var array<string, array<string, mixed>> */
    private array $packs = [];

    /** @return array<string, mixed> the pack of $country (an empty pack when there is none) */
    public function of(?string $country): array
    {
        $code = strtoupper((string) $country);

        if (isset($this->packs[$code])) {
            return $this->packs[$code];
        }

        $file = base_path("country-packs/{$code}/hr.json");
        $pack = $code !== '' && preg_match('/^[A-Z]{2}\z/', $code) === 1 && is_file($file)
            ? json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR)
            : [];

        return $this->packs[$code] = [
            'identifier_types' => $pack['identifier_types'] ?? [],
            'statutory_leave_types' => $pack['statutory_leave_types'] ?? [],
        ];
    }

    /** @return array<string, array{key: string, required: bool, pattern: ?string}> by key */
    public function identifierTypes(?string $country): array
    {
        $types = [];

        foreach ($this->of($country)['identifier_types'] as $type) {
            $types[$type['key']] = ['key' => $type['key'], 'required' => (bool) ($type['required'] ?? false), 'pattern' => $type['pattern'] ?? null];
        }

        return $types;
    }

    /**
     * The statutory leave types in force on $on.
     *
     * @return list<array<string, mixed>>
     */
    public function statutoryLeaveTypes(?string $country, CarbonImmutable $on): array
    {
        $date = $on->toDateString();

        return array_values(array_filter(
            $this->of($country)['statutory_leave_types'],
            fn (array $type) => ($type['effective_from'] ?? '0000-01-01') <= $date && (($type['effective_to'] ?? null) === null || $type['effective_to'] >= $date),
        ));
    }

    public static function label(string $kind, string $key): string
    {
        $label = __("hr.pack.{$kind}.{$key}");

        return $label === "hr.pack.{$kind}.{$key}" ? $key : $label;
    }
}
