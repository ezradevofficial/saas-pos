<?php

namespace App\Core\DataExport;

use App\Core\Audit\Auditor;
use App\Core\DataExport\Jobs\BuildTenantExport;
use App\Core\DataExport\Models\TenantExport;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Notifications\LocalDate;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Throwable;

/**
 * EXP-02: full tenant data exports.
 *
 * - request(): an Owner asks for one (the caller checks RBAC-10 and
 *   `core.tenant.export`). One at a time per tenant (409, and a partial
 *   unique index), at most `data_export.per_day` in any 24 hours (429).
 *   Audited as `core.tenant_export.request`; the build is queued.
 * - build(): run by BuildTenantExport in the tenant's context. The zip goes
 *   to the media disk at `tenants/{tenant}/exports/{id}.zip`, kept
 *   `data_export.keep_days`. Audited as `core.tenant_export.ready` (or
 *   `.fail`); the requester is notified (`core.tenant_export.ready`).
 * - url(): a temporary signed URL bound to the requester, valid
 *   `data_export.link_hours` (never past the file's expiry). The download
 *   (TenantExportFileController) checks the signed-for user is still an
 *   active Owner and is audited as `core.tenant_export.download`.
 * - expireDue(): deletes files past their expiry and marks them expired
 *   (`core.tenant_export.expire`); fails builds a dead worker left behind.
 */
class TenantExports
{
    public const READY_EVENT = 'core.tenant_export.ready';

    /** A build still queued or running after this long was left by a dead worker. */
    public const STALE_HOURS = 6;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly TenantExportBuilder $builder,
        private readonly Notifier $notifier,
    ) {}

    public function request(User $owner): TenantExport
    {
        $export = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($owner) {
            // Serialise requests of one tenant: the checks below then see each other.
            DB::connection(TenantContext::CONNECTION)->select("select pg_advisory_xact_lock(hashtext('tenant-export:' || ?))", [$this->tenants->require()]);

            if (TenantExport::query()->whereIn('status', TenantExport::ACTIVE)->exists()) {
                throw new ApiException(409, 'export_in_progress', __('data_export.errors.in_progress'));
            }

            $perDay = max(1, (int) config('data_export.per_day', 3));
            $recent = TenantExport::query()->where('created_at', '>', CarbonImmutable::now()->subDay())->count();

            if ($recent >= $perDay) {
                throw new ApiException(429, 'export_limit', __('data_export.errors.limit', ['count' => $perDay]));
            }

            try {
                $export = TenantExport::create(['requested_by' => $owner->id, 'status' => TenantExport::QUEUED]);
            } catch (UniqueConstraintViolationException) {
                throw new ApiException(409, 'export_in_progress', __('data_export.errors.in_progress'));
            }

            $this->auditor->record('core.tenant_export.request', $export, null, ['status' => TenantExport::QUEUED]);

            return $export;
        });

        BuildTenantExport::dispatch($export->tenant_id, $export->id)->afterCommit();

        return $export->refresh();
    }

    /** Build $export's zip (BuildTenantExport, in the tenant's context). */
    public function build(TenantExport $export): void
    {
        if ($export->status !== TenantExport::QUEUED) {
            return;
        }

        $export->forceFill(['status' => TenantExport::RUNNING, 'started_at' => CarbonImmutable::now()])->save();
        $built = null;
        $path = sprintf('tenants/%s/exports/%s.zip', $this->tenants->require(), $export->id);
        $disk = Storage::disk(TenantExportBuilder::MEDIA_DISK);

        try {
            $built = $this->builder->build($export->id);
            $stream = fopen($built['zip'], 'rb');

            try {
                $disk->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $size = (int) filesize($built['zip']);
            $sha = (string) hash_file('sha256', $built['zip']);
            $now = CarbonImmutable::now();

            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export, $built, $path, $size, $sha, $now) {
                $export->forceFill([
                    'status' => TenantExport::READY,
                    'disk' => TenantExportBuilder::MEDIA_DISK,
                    'path' => $path,
                    'size' => $size,
                    'sha256' => $sha,
                    'table_count' => $built['tables'],
                    'row_count' => $built['rows'],
                    'file_count' => $built['files'],
                    'completed_at' => $now,
                    'expires_at' => $now->addDays(max(1, (int) config('data_export.keep_days', 7))),
                ])->save();

                $this->auditor->record('core.tenant_export.ready', $export, ['status' => TenantExport::RUNNING], [
                    'status' => TenantExport::READY, 'size' => $size, 'sha256' => $sha,
                    'tables' => $built['tables'], 'rows' => $built['rows'], 'files' => $built['files'],
                ]);
            });
        } catch (Throwable $e) {
            $disk->delete($path);
            $this->fail($export, 'build_failed');
            report($e);

            return;
        } finally {
            if ($built !== null) {
                $this->builder->cleanup($built['dir']);
            }
        }

        $this->notifyReady($export);
    }

    public function fail(TenantExport $export, string $failure): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export, $failure) {
            $before = $export->status;
            $export->forceFill(['status' => TenantExport::FAILED, 'failure' => $failure, 'completed_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('core.tenant_export.fail', $export, ['status' => $before], ['status' => TenantExport::FAILED, 'failure' => $failure]);
        });
    }

    /** The signed download URL of $export for $user, or null when it is not theirs or not ready. */
    public function url(TenantExport $export, User $user): ?string
    {
        if ($export->requested_by !== $user->id || ! $export->isDownloadable()) {
            return null;
        }

        $expires = CarbonImmutable::now()->addHours(max(1, (int) config('data_export.link_hours', 24)));

        if ($export->expires_at->isBefore($expires)) {
            $expires = $export->expires_at;
        }

        return URL::temporarySignedRoute('tenant_exports.download', $expires, [
            'tenant_export' => $export->id,
            'tenant' => $export->tenant_id,
            'user' => $user->id,
        ]);
    }

    /** Record that $user downloaded $export (AUD-01). */
    public function downloaded(TenantExport $export, User $user, ?string $ip, ?string $userAgent): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export, $user, $ip, $userAgent) {
            $export->forceFill(['downloaded_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('core.tenant_export.download', $export, null, ['size' => $export->size, 'sha256' => $export->sha256], [
                'user_id' => $user->id, 'ip' => $ip, 'user_agent' => $userAgent,
            ]);
        });
    }

    /**
     * Delete the files of the current tenant's exports past their expiry
     * (TEN-06: the rows stay, as `expired`) and fail builds left queued or
     * running for STALE_HOURS. Returns how many exports changed.
     */
    public function expireDue(CarbonImmutable $at): int
    {
        $changed = 0;

        TenantExport::query()->where('status', TenantExport::READY)->where('expires_at', '<=', $at)->orderBy('id')
            ->each(function (TenantExport $export) use (&$changed) {
                if ($export->disk !== null && $export->path !== null) {
                    Storage::disk($export->disk)->delete($export->path);
                }

                DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export) {
                    $export->forceFill(['status' => TenantExport::EXPIRED, 'path' => null])->save();
                    $this->auditor->record('core.tenant_export.expire', $export, ['status' => TenantExport::READY], ['status' => TenantExport::EXPIRED]);
                });
                $changed++;
            });

        TenantExport::query()->whereIn('status', TenantExport::ACTIVE)->where('created_at', '<=', $at->subHours(self::STALE_HOURS))->orderBy('id')
            ->each(function (TenantExport $export) use (&$changed) {
                $this->fail($export, 'stale');
                $changed++;
            });

        return $changed;
    }

    /** @return array<string, mixed> the export as the API shows it to $viewer */
    public function present(TenantExport $export, User $viewer): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'requested_by' => ['id' => $export->requested_by, 'name' => $export->requester?->name],
            'size' => $export->size,
            'sha256' => $export->sha256,
            'tables' => $export->table_count,
            'rows' => $export->row_count,
            'files' => $export->file_count,
            'failure' => $export->failure,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'downloaded_at' => $export->downloaded_at?->toIso8601String(),
            'download_url' => $this->url($export, $viewer),
        ];
    }

    private function notifyReady(TenantExport $export): void
    {
        $requester = $export->requester;

        if ($requester === null) {
            return;
        }

        $this->notifier->send(new NotificationEvent(self::READY_EVENT, [$requester], [
            'size' => Number::fileSize((int) $export->size, 1, null),
            'expires_at' => LocalDate::format($export->expires_at, $requester->locale),
        ], '/settings/data-export'));
    }
}
