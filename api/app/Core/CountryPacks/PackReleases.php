<?php

namespace App\Core\CountryPacks;

use App\Core\CountryPacks\Jobs\PropagatePackToTenant;
use App\Core\CountryPacks\Models\CountryPackRelease;
use App\Core\Http\ApiException;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use App\Core\Tenancy\DueTenants;
use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Calendar\HolidayFile;
use App\Core\Workflow\Calendar\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADM-04 (ADR 022): country pack releases from the console.
 *
 * - load(): a JSON document for one country and kind, with an effective
 *   date. It is validated and diffed against the pack in force at once
 *   and stored as the next version (draft), errors and all, so staff see
 *   what to fix.
 * - approve(): by a staff member holding `platform.packs.approve` who did
 *   not load it; refused while it has errors.
 * - publish(): by a holder of `platform.packs.publish` who did not
 *   approve it. From its effective date it is the pack the app reads
 *   (PackDocuments). Tax and holidays are also written into the pack
 *   tables then (apply()): a new tax pack version propagated to tenants
 *   (ADR 007), or the country's public holidays replaced. A release dated
 *   later is applied by `country-packs:apply-releases` (daily) on its date.
 *
 * Every step is audited in the platform chain.
 */
class PackReleases
{
    public function __construct(
        private readonly PackValidator $validator,
        private readonly PackDocuments $documents,
        private readonly PlatformAuditor $audit,
        private readonly CountryPacks $packs,
        private readonly DueTenants $due,
    ) {}

    /** @param array<string, mixed> $content */
    public function load(string $country, string $kind, array $content, CarbonImmutable $effectiveFrom, ?string $notes, PlatformUser $staff): CountryPackRelease
    {
        $base = $this->documents->current($country, $kind);
        $validation = $this->validator->validate($country, $kind, $content);
        $diff = [
            'base' => ['source' => $base['source'], 'version' => $base['version']],
            ...$this->validator->diff($kind, $base['content'], $content),
        ];

        $release = PlatformDatabase::transaction(function () use ($country, $kind, $content, $effectiveFrom, $notes, $staff, $validation, $diff) {
            PlatformDatabase::connection()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["pack_release:{$country}:{$kind}"]);
            $version = (int) CountryPackRelease::query()->where('country', $country)->where('kind', $kind)->max('version') + 1;

            return CountryPackRelease::query()->create([
                'country' => $country,
                'kind' => $kind,
                'version' => $version,
                'status' => CountryPackRelease::DRAFT,
                'content' => $content,
                'content_hash' => hash('sha256', (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
                'effective_from' => $effectiveFrom->toDateString(),
                'notes' => $notes,
                'validation' => $validation,
                'diff' => $diff,
                'loaded_by' => $staff->id,
            ]);
        });

        $this->audit->record('platform.pack.load', $release, null, ['country' => $country, 'kind' => $kind, 'version' => $release->version, 'effective_from' => $effectiveFrom->toDateString(), 'errors' => count($validation['errors']), 'diff' => $diff]);

        return $release;
    }

    /**
     * Review L7: undo a published release by loading the content before it
     * (the previous published release, else the shipped file) as a new
     * draft from today. It still goes through approval and publishing.
     */
    public function supersede(CountryPackRelease $release, PlatformUser $staff): CountryPackRelease
    {
        if ($release->status !== CountryPackRelease::PUBLISHED) {
            throw new ApiException(422, 'pack_release_not_published', __('country_packs.releases.not_published'));
        }

        $previous = CountryPackRelease::query()->where('country', $release->country)->where('kind', $release->kind)
            ->where('status', CountryPackRelease::PUBLISHED)->where('version', '<', $release->version)->orderByDesc('version')->first();
        $file = PackDocuments::file($release->country, $release->kind);
        $content = $previous?->content ?? ($file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR) : null);

        if ($content === null) {
            throw new ApiException(422, 'pack_release_nothing_before', __('country_packs.releases.nothing_before'));
        }

        return $this->load($release->country, $release->kind, $content, CarbonImmutable::now()->utc()->startOfDay(),
            __('country_packs.releases.supersede_notes', ['version' => $previous?->version ?? '0']), $staff);
    }

    public function approve(CountryPackRelease $release, PlatformUser $staff): CountryPackRelease
    {
        $release = PlatformDatabase::transaction(function () use ($release, $staff) {
            $release = $this->locked($release);

            if ($release->status !== CountryPackRelease::DRAFT) {
                throw new ApiException(422, 'pack_release_not_draft', __('country_packs.releases.not_draft'));
            }

            if ($release->hasErrors()) {
                throw new ApiException(422, 'pack_release_invalid', __('country_packs.releases.invalid'));
            }

            if ($release->loaded_by === $staff->id) {
                throw new ApiException(403, 'pack_release_own', __('country_packs.releases.own_load'));
            }

            $release->fill(['status' => CountryPackRelease::APPROVED, 'approved_by' => $staff->id, 'approved_at' => now()])->save();

            return $release;
        });

        $this->audit->record('platform.pack.approve', $release, ['status' => CountryPackRelease::DRAFT], ['status' => $release->status, 'country' => $release->country, 'kind' => $release->kind, 'version' => $release->version]);

        return $release;
    }

    public function reject(CountryPackRelease $release, PlatformUser $staff, ?string $note): CountryPackRelease
    {
        $release = PlatformDatabase::transaction(function () use ($release, $staff, $note) {
            $release = $this->locked($release);

            if (! in_array($release->status, [CountryPackRelease::DRAFT, CountryPackRelease::APPROVED], true)) {
                throw new ApiException(422, 'pack_release_decided', __('country_packs.releases.decided'));
            }

            $before = $release->status;
            $release->fill(['status' => CountryPackRelease::REJECTED, 'rejected_by' => $staff->id, 'rejected_at' => now(), 'rejection_note' => $note])->save();

            return [$release, $before];
        });

        $this->audit->record('platform.pack.reject', $release[0], ['status' => $release[1]], ['status' => CountryPackRelease::REJECTED, 'note' => $note]);

        return $release[0];
    }

    /** ADM-04c: the second person publishes; applied at once when its date has come. */
    public function publish(CountryPackRelease $release, PlatformUser $staff): CountryPackRelease
    {
        $release = PlatformDatabase::transaction(function () use ($release, $staff) {
            $release = $this->locked($release);

            if ($release->status !== CountryPackRelease::APPROVED) {
                throw new ApiException(422, 'pack_release_not_approved', __('country_packs.releases.not_approved'));
            }

            if ($release->approved_by === $staff->id) {
                throw new ApiException(403, 'pack_release_own_approval', __('country_packs.releases.own_approval'));
            }

            $release->fill(['status' => CountryPackRelease::PUBLISHED, 'published_by' => $staff->id, 'published_at' => now()])->save();

            return $release;
        });

        $this->audit->record('platform.pack.publish', $release, ['status' => CountryPackRelease::APPROVED], ['status' => $release->status, 'country' => $release->country, 'kind' => $release->kind, 'version' => $release->version, 'effective_from' => $release->effective_from->toDateString()]);

        if ($release->effective_from->toDateString() <= CarbonImmutable::now()->utc()->toDateString()) {
            $this->apply($release);
        }

        return $release->fresh();
    }

    /**
     * Tax and holidays releases in force are written into the pack tables
     * once (`applied_at`); a later published release of the same country
     * and kind supersedes an earlier one, so only the one in force applies.
     *
     * @return int releases applied
     */
    public function applyDue(): int
    {
        $applied = 0;

        foreach (['tax', 'holidays'] as $kind) {
            $pending = CountryPackRelease::query()->where('kind', $kind)->where('status', CountryPackRelease::PUBLISHED)->whereNull('applied_at')
                ->where('effective_from', '<=', CarbonImmutable::now()->utc()->toDateString())->distinct()->pluck('country');

            foreach ($pending as $country) {
                $release = $this->documents->release($country, $kind);

                if ($release !== null && $release->applied_at === null) {
                    $this->apply(CountryPackRelease::query()->findOrFail($release->id));
                    $applied++;
                }
            }
        }

        return $applied;
    }

    /** Write a tax or holidays release into the pack tables (other kinds are read where they are). */
    public function apply(CountryPackRelease $release): void
    {
        if ($release->kind === 'tax') {
            [$pack] = $this->packs->publish(PackFile::fromArray($release->content), PlatformDatabase::CONNECTION);
            // Review H3, M4: every tenant that is not closed (suspended ones too, so a reactivated
            // tenant is current), one queued job each: a failure stays with its tenant and is retried.
            $tenants = $this->due->forPackUpdates();

            foreach ($tenants as $tenantId) {
                try {
                    PropagatePackToTenant::dispatch($pack->id, $tenantId);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            Log::info('country pack release applied', ['release' => $release->id, 'pack_version' => $pack->version, 'tenants' => count($tenants)]);
        } elseif ($release->kind === 'holidays') {
            $file = HolidayFile::fromArray($release->content);

            PlatformDatabase::transaction(function () use ($file, $release) {
                PublicHoliday::on(PlatformDatabase::CONNECTION)->where('country', $release->country)->delete();

                foreach ($file->holidays() as $holiday) {
                    PublicHoliday::on(PlatformDatabase::CONNECTION)->create(['country' => $release->country, ...$holiday]);
                }
            });
            app(BusinessCalendar::class)->forgetHolidays();
        }

        if (in_array($release->kind, ['tax', 'holidays'], true)) {
            $release->forceFill(['applied_at' => now()])->save();
            $this->audit->record('platform.pack.apply', $release, null, ['country' => $release->country, 'kind' => $release->kind, 'version' => $release->version]);
        }
    }

    private function locked(CountryPackRelease $release): CountryPackRelease
    {
        return CountryPackRelease::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();
    }
}
