<?php

namespace App\Core\CountryPacks;

use App\Core\CountryPacks\Models\CountryPackRelease;
use App\Core\Platform\PlatformDatabase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ADM-04, CP-01 (ADR 022): where a country's pack document comes from.
 * The latest published release whose effective date has come (UTC) is the
 * pack; without one, the shipped file (`api/country-packs/{CODE}/{file}`).
 * Readers (tax, holidays, payroll, HR, fixed asset tax classes, chart
 * templates) ask here first and fall back to their file.
 *
 * Reads go through the runtime role (releases are reference data, the
 * same for every tenant). Nothing is cached across calls, so a long-running
 * worker picks up a newly published release at once; callers that parse
 * may cache by release() id.
 */
class PackDocuments
{
    /** Kind => the shipped file it overrides. */
    public const KINDS = [
        'tax' => 'pack.json',
        'payroll' => 'payroll.json',
        'hr' => 'hr.json',
        'holidays' => 'holidays.json',
        'fixed_assets' => 'fixed_assets.json',
        'accounts' => 'accounts.json',
    ];

    /** The published release of $country and $kind in force on $on (today, UTC), or null. */
    public function release(string $country, string $kind, ?CarbonInterface $on = null): ?CountryPackRelease
    {
        if (preg_match('/^[A-Z]{2}\z/', $country) !== 1 || ! isset(self::KINDS[$kind])) {
            return null;
        }

        return CountryPackRelease::on(PlatformDatabase::READ)
            ->where('country', $country)->where('kind', $kind)
            ->where('status', CountryPackRelease::PUBLISHED)
            ->where('effective_from', '<=', ($on ?? CarbonImmutable::now())->utc()->toDateString())
            ->orderByDesc('version')
            ->first();
    }

    /** The release's content, or null (callers read the shipped file). */
    public function content(string $country, string $kind): ?array
    {
        return $this->release($country, $kind)?->content;
    }

    /**
     * What is in force now: the release, else the shipped file.
     *
     * @return array{content: ?array<string, mixed>, source: string, version: ?int, release_id: ?string, effective_from: ?string, sha256: ?string}
     */
    public function current(string $country, string $kind): array
    {
        $release = $this->release($country, $kind);

        if ($release !== null) {
            return ['content' => $release->content, 'source' => 'release', 'version' => $release->version, 'release_id' => $release->id, 'effective_from' => $release->effective_from->toDateString(), 'sha256' => $release->content_hash];
        }

        $file = self::file($country, $kind);
        $raw = $file !== null && is_file($file) ? (string) file_get_contents($file) : null;

        return [
            'content' => $raw === null ? null : json_decode($raw, true, 64, JSON_THROW_ON_ERROR),
            'source' => $raw === null ? 'none' : 'shipped',
            'version' => null,
            'release_id' => null,
            'effective_from' => null,
            'sha256' => $raw === null ? null : hash('sha256', $raw),
        ];
    }

    /** @return list<string> countries with a published release of $kind in force */
    public function countries(string $kind): array
    {
        return CountryPackRelease::on(PlatformDatabase::READ)->where('kind', $kind)->where('status', CountryPackRelease::PUBLISHED)
            ->where('effective_from', '<=', CarbonImmutable::now()->utc()->toDateString())
            ->distinct()->orderBy('country')->pluck('country')->all();
    }

    public static function file(string $country, string $kind): ?string
    {
        return preg_match('/^[A-Z]{2}\z/', $country) === 1 && isset(self::KINDS[$kind]) ? base_path("country-packs/{$country}/".self::KINDS[$kind]) : null;
    }
}
