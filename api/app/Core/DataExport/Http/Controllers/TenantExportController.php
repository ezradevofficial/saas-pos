<?php

namespace App\Core\DataExport\Http\Controllers;

use App\Core\DataExport\Http\Requests\StoreTenantExportRequest;
use App\Core\DataExport\Http\Requests\TenantExportDownloadRequest;
use App\Core\DataExport\Http\Requests\TenantExportRequest;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DataExport\TenantExports;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\PasswordCheck;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * EXP-02: Settings → Data export (Owners only, TenantExportRequest).
 *
 * - index: the tenant's exports, newest first (the last 20). Never a URL:
 *   `can_download` says whether the viewer may ask for one.
 * - store: request a new export, confirming the Owner's password
 *   (PasswordCheck; wrong ones count towards the lockout). One at a time,
 *   a few a day; built in the background.
 * - link: a signed download URL for the requester, valid a few minutes.
 * - download: the zip behind the signed URL. The URL names the tenant and
 *   the user; the controller enters that tenant, finds the export under
 *   row-level security and serves it only to its requester while they are
 *   an active Owner and the export is ready and not expired. Audited.
 */
class TenantExportController
{
    public function __construct(private readonly TenantExports $exports) {}

    public function index(TenantExportRequest $request): JsonResponse
    {
        $user = $request->user();
        $exports = TenantExport::query()->with('requester')->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'data' => $exports->map(fn (TenantExport $export) => $this->exports->present($export, $user))->values(),
            'meta' => [
                'per_day' => (int) config('data_export.per_day', 3),
                'link_minutes' => (int) config('data_export.link_minutes', 5),
                'keep_days' => (int) config('data_export.keep_days', 7),
            ],
        ]);
    }

    public function store(StoreTenantExportRequest $request, PasswordCheck $password): JsonResponse
    {
        // The whole tenant leaves with this: a stolen session alone is not enough.
        $password->confirm($request->user(), (string) $request->validated('password'));
        $export = $this->exports->request($request->user());
        $export->load('requester');

        return response()->json(['data' => $this->exports->present($export, $request->user())], 202);
    }

    /** A download link for the requester, valid a few minutes (never listed). */
    public function link(TenantExportRequest $request, TenantExport $tenantExport): JsonResponse
    {
        return response()->json(['data' => $this->exports->link($tenantExport, $request->user())]);
    }

    public function download(TenantExportDownloadRequest $request, TenantContext $tenants, string $export): Response
    {
        $tenantId = (string) $request->query('tenant');
        $userId = (string) $request->query('user');
        abort_unless(Str::isUuid($tenantId) && Str::isUuid($userId) && Str::isUuid($export), 404);

        return $tenants->run($tenantId, function () use ($request, $export, $userId) {
            $export = TenantExport::query()->find($export);
            $user = User::query()->find($userId);

            abort_unless(
                $export !== null && $user !== null && $user->isActive()
                && $export->requested_by === $user->id
                && $export->isDownloadable()
                && app(OwnerGuard::class)->isOwner($user),
                404,
            );

            $disk = Storage::disk((string) $export->disk);
            abort_unless($disk->exists((string) $export->path), 404);

            $this->exports->downloaded($export, $user, $request->ip(), $request->userAgent());

            return $disk->download((string) $export->path, sprintf('tenant-export-%s.zip', $export->created_at?->utc()->format('Ymd-His')), [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
