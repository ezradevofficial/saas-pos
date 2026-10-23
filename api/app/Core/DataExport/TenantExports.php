<?php

namespace App\Core\DataExport;

use App\Core\Audit\Auditor;
use App\Core\DataExport\Jobs\BuildTenantExport;
use App\Core\DataExport\Models\TenantExport;
use App\Core\Files\TenantFiles;
use App\Core\Http\ApiException;
use App\Core\Http\SignedUrls;
use App\Core\Identity\Models\User;
use App\Core\Notifications\LocalDate;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\ClosedTenant;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Throwable;

/**
 * EXP-02: full tenant data exports.
 *
 * - request(): an Owner asks for one (the caller checks RBAC-10,
 *   `core.tenant.export` and the Owner's password). One at a time per
 *   tenant (409, and a partial unique index), at most
 *   `data_export.per_day` in any 24 hours (429). Audited as
 *   `core.tenant_export.request`; the build is queued.
 * - build(): run by BuildTenantExport in the tenant's context. The final
 *   path is recorded before anything is written, the zip is uploaded under
 *   `{path}.part` and moved into place once complete, so a failed or stale
 *   build always knows what to delete. Kept `data_export.keep_days`.
 *   Audited as `core.tenant_export.ready` (or `.fail`); the requester is
 *   notified (`core.tenant_export.ready`).
 * - link(): a temporary signed URL bound to the requester, minted only on
 *   an authenticated request and valid `data_export.link_minutes`. The
 *   download (TenantExportController::download) checks the signed-for
 *   user is still an active Owner and is audited.
 * - requestOnClosing(): when the tenant closes (TenantClosed), its full
 *   export is built once per closing (`purpose = closing`, unique on the
 *   tenant and its closing time, so a tenant reopened and closed again gets
 *   a fresh one), kept
 *   `data_export.closing_link_days` (30), and each active Owner with an
 *   email is sent a signed link that works without signing in, since a
 *   closed tenant refuses sign-in (sendClosingLinks). Not for a developer
 *   sandbox (sample data), nor a tenant with no active Owner (a pruned
 *   sign-up). Staff may send the links again (resendClosingLinks).
 * - expireDue(): deletes files past their expiry and marks them expired
 *   (`core.tenant_export.expire`), keeping the row ready to retry when the
 *   delete fails; fails builds running since before the job timeout (plus
 *   an hour) or queued for `data_export.queued_stale_hours`.
 */
class TenantExports
{
    public const READY_EVENT = 'core.tenant_export.ready';

    /** The signed, sign-in-free download of a closed tenant's export. */
    public const CLOSING_ROUTE = 'tenant_exports.closing_download';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly TenantExportBuilder $builder,
        private readonly Notifier $notifier,
    ) {}

    /** A build running since before this was left by a dead worker. */
    public static function runningStaleBefore(CarbonImmutable $at): CarbonImmutable
    {
        return $at->subSeconds((int) config('data_export.timeout', 3600) + 3600);
    }

    /** A build queued since before this found no worker. */
    public static function queuedStaleBefore(CarbonImmutable $at): CarbonImmutable
    {
        return $at->subHours(max(1, (int) config('data_export.queued_stale_hours', 24)));
    }

    public function request(User $owner): TenantExport
    {
        $export = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($owner) {
            // Serialise requests of one tenant: the checks below then see each other.
            DB::connection(TenantContext::CONNECTION)->select("select pg_advisory_xact_lock(hashtext('tenant-export:' || ?))", [$this->tenants->require()]);

            if (TenantExport::query()->where('purpose', TenantExport::PURPOSE_REQUEST)->whereIn('status', TenantExport::ACTIVE)->exists()) {
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

        $path = TenantFiles::key($this->tenants->require(), 'exports', $export->id.'.zip');
        $diskName = TenantFiles::disk();
        // The path is known before anything is written: clean-up finds it.
        $export->forceFill([
            'status' => TenantExport::RUNNING,
            'started_at' => CarbonImmutable::now(),
            'disk' => $diskName,
            'path' => $path,
        ])->save();
        $built = null;
        $disk = Storage::disk($diskName);

        try {
            $built = $this->builder->build($export->id);
            $stream = fopen($built['zip'], 'rb');

            try {
                $disk->writeStream("{$path}.part", $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $disk->move("{$path}.part", $path);
            $size = (int) filesize($built['zip']);
            $sha = (string) hash_file('sha256', $built['zip']);
            $now = CarbonImmutable::now();

            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export, $built, $size, $sha, $now) {
                $export->forceFill([
                    'status' => TenantExport::READY,
                    'size' => $size,
                    'sha256' => $sha,
                    'table_count' => $built['tables'],
                    'row_count' => $built['rows'],
                    'file_count' => $built['files'],
                    'completed_at' => $now,
                    'expires_at' => $export->purpose === TenantExport::PURPOSE_CLOSING
                        ? $now->addDays(max(1, (int) config('data_export.closing_link_days', 30)))
                        : $now->addDays(max(1, (int) config('data_export.keep_days', 7))),
                ])->save();

                $this->auditor->record('core.tenant_export.ready', $export, ['status' => TenantExport::RUNNING], [
                    'status' => TenantExport::READY, 'size' => $size, 'sha256' => $sha,
                    'tables' => $built['tables'], 'rows' => $built['rows'], 'files' => $built['files'],
                ]);
            });
        } catch (ExportTooLarge) {
            $this->fail($export, 'too_large');

            return;
        } catch (Throwable $e) {
            $this->fail($export, 'build_failed');
            report($e);

            return;
        } finally {
            if ($built !== null) {
                $this->builder->cleanup($built['dir']);
            }
        }

        if ($export->purpose === TenantExport::PURPOSE_CLOSING) {
            // A mail failure is reported, never retried as a build: staff can send the links again.
            rescue(fn () => $this->sendClosingLinks($export), report: true);

            return;
        }

        $this->notifyReady($export);
    }

    /**
     * The closing export of the tenant in context for its current closing,
     * queued once per closing (the TenantClosed listener). A closing is
     * named by the time the tenant closed (its updated_at as the status
     * became closed): reopened and closed again, it gets a fresh export.
     * Null for a tenant that is not closed, a developer sandbox, or a
     * tenant with no active Owner. Runs inside the closing transaction:
     * the build is queued after it commits.
     */
    public function requestOnClosing(): ?TenantExport
    {
        $tenant = Tenant::query()->whereKey($this->tenants->require())->first();
        $owners = $this->activeOwners();

        if ($tenant === null || $tenant->status !== 'closed' || $tenant->updated_at === null || $tenant->is_sandbox || $owners === []) {
            return null;
        }

        $closedAt = CarbonImmutable::instance($tenant->updated_at);
        $ofThisClosing = fn () => TenantExport::query()->where('purpose', TenantExport::PURPOSE_CLOSING)->where('closed_at', $closedAt)->first();
        $existing = $ofThisClosing();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $export = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($owners, $closedAt) {
                $export = TenantExport::query()->create(['requested_by' => $owners[0]->id, 'status' => TenantExport::QUEUED, 'purpose' => TenantExport::PURPOSE_CLOSING, 'closed_at' => $closedAt]);
                $this->auditor->record('core.tenant_export.closing_request', $export, null, ['status' => TenantExport::QUEUED, 'purpose' => TenantExport::PURPOSE_CLOSING, 'closed_at' => $closedAt->toIso8601String()]);

                return $export;
            });
        } catch (UniqueConstraintViolationException) {
            return $ofThisClosing();
        }

        BuildTenantExport::dispatch($export->tenant_id, $export->id)->afterCommit();

        return $export;
    }

    /**
     * Email each active Owner of the closed tenant a signed link to $export,
     * valid until the export expires. Audited (the count, never a link).
     * Returns how many were sent.
     */
    public function sendClosingLinks(TenantExport $export, string $action = 'core.tenant_export.closing_links', array $details = []): int
    {
        if ($export->purpose !== TenantExport::PURPOSE_CLOSING || ! $export->isDownloadable() || ! ClosedTenant::is($export->tenant_id)) {
            throw new ApiException(409, 'closing_export_unavailable', __('data_export.errors.closing_unavailable'));
        }

        $tenant = Tenant::query()->whereKey($export->tenant_id)->firstOrFail();
        $size = Number::fileSize((int) $export->size, 1, null);
        $sent = 0;

        foreach ($this->activeOwners() as $owner) {
            if ($owner->email === null) {
                continue;
            }

            $url = URL::temporarySignedRoute(self::CLOSING_ROUTE, $export->expires_at, ['export' => $export->id, 'tenant' => $export->tenant_id, 'user' => $owner->id]);
            $owner->notify(new ClosingExportReady((string) $tenant->name, $url, LocalDate::format($export->expires_at, $owner->locale), $size));
            $sent++;
        }

        $this->auditor->record($action, $export, null, ['recipients' => $sent, 'expires_at' => $export->expires_at?->toIso8601String(), ...$details]);

        return $sent;
    }

    /** Staff send the closing links again from the console (PlatformTenants::resendClosingExport), with a reason. */
    public function resendClosingLinks(string $reason): int
    {
        // The latest closing's export.
        $export = TenantExport::query()->where('purpose', TenantExport::PURPOSE_CLOSING)->orderByDesc('closed_at')->first();

        if ($export === null) {
            throw new ApiException(409, 'closing_export_unavailable', __('data_export.errors.closing_unavailable'));
        }

        return $this->sendClosingLinks($export, 'core.tenant_export.closing_resend', ['reason' => $reason]);
    }

    /** @return list<User> the tenant's active Owners, oldest first */
    private function activeOwners(): array
    {
        return User::query()->whereKey(app(OwnerGuard::class)->ownerIds())->where('status', User::STATUS_ACTIVE)->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /** Fail $export and delete whatever its build stored (the file and its upload in progress). */
    public function fail(TenantExport $export, string $failure): void
    {
        // A file that could not be deleted keeps its path: expireDue() tries again.
        $deleted = $this->deleteFiles($export);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export, $failure, $deleted) {
            $before = $export->status;
            $export->forceFill(['status' => TenantExport::FAILED, 'failure' => $failure, 'completed_at' => CarbonImmutable::now(), 'path' => $deleted ? null : $export->path])->save();
            $this->auditor->record('core.tenant_export.fail', $export, ['status' => $before], ['status' => TenantExport::FAILED, 'failure' => $failure]);
        });
    }

    /**
     * A signed download URL of $export for $user, valid a few minutes.
     *
     * @return array{url: string, expires_at: string}
     *
     * @throws ApiException 404 when it is not theirs or not downloadable
     */
    public function link(TenantExport $export, User $user): array
    {
        if ($export->requested_by !== $user->id || ! $export->isDownloadable()) {
            throw new ApiException(404, 'not_found', __('data_export.errors.not_downloadable'));
        }

        $expires = CarbonImmutable::now()->addMinutes(max(1, (int) config('data_export.link_minutes', 5)));

        if ($export->expires_at->isBefore($expires)) {
            $expires = $export->expires_at;
        }

        return [
            'url' => SignedUrls::temporary('tenant_exports.download', $expires, [
                'export' => $export->id,
                'tenant' => $export->tenant_id,
                'user' => $user->id,
            ]),
            'expires_at' => $expires->toIso8601String(),
        ];
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
     * (TEN-06: the rows stay, as `expired`) and fail stale builds. An
     * export whose file could not be deleted stays ready (past its expiry,
     * so no longer downloadable) and is tried again on the next run.
     * Returns how many exports changed.
     */
    public function expireDue(CarbonImmutable $at): int
    {
        $changed = 0;

        TenantExport::query()->where('status', TenantExport::READY)->where('expires_at', '<=', $at)->orderBy('id')
            ->each(function (TenantExport $export) use (&$changed) {
                if (! $this->deleteFiles($export)) {
                    return;
                }

                DB::connection(TenantContext::CONNECTION)->transaction(function () use ($export) {
                    $export->forceFill(['status' => TenantExport::EXPIRED, 'path' => null])->save();
                    $this->auditor->record('core.tenant_export.expire', $export, ['status' => TenantExport::READY], ['status' => TenantExport::EXPIRED]);
                });
                $changed++;
            });

        // Failed builds whose files could not be deleted then.
        TenantExport::query()->where('status', TenantExport::FAILED)->whereNotNull('path')->orderBy('id')
            ->each(function (TenantExport $export) use (&$changed) {
                if ($this->deleteFiles($export)) {
                    $export->forceFill(['path' => null])->save();
                    $changed++;
                }
            });

        $stale = TenantExport::query()
            ->where(fn ($q) => $q->where('status', TenantExport::RUNNING)->where('started_at', '<=', self::runningStaleBefore($at)))
            ->orWhere(fn ($q) => $q->where('status', TenantExport::QUEUED)->where('created_at', '<=', self::queuedStaleBefore($at)))
            ->orderBy('id');

        $stale->each(function (TenantExport $export) use (&$changed) {
            $this->fail($export, 'stale');
            $changed++;
        });

        return $changed;
    }

    /** @return array<string, mixed> the export as the API shows it (never a download URL: link() mints one) */
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
            'can_download' => $export->requested_by === $viewer->id && $export->isDownloadable(),
        ];
    }

    /** Delete $export's file and any upload in progress; false when one is still there. */
    private function deleteFiles(TenantExport $export): bool
    {
        if ($export->disk === null || $export->path === null) {
            return true;
        }

        $disk = Storage::disk($export->disk);
        $ok = true;

        foreach ([$export->path, "{$export->path}.part"] as $path) {
            try {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    $ok = false;
                }
            } catch (Throwable $e) {
                $ok = false;
            }

            if (! $ok) {
                Log::warning('Tenant export file could not be deleted; retrying on the next run.', ['export' => $export->id, 'path' => $path]);

                return false;
            }
        }

        return true;
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
