<?php

namespace App\Core\Approvals\Http\Controllers;

use App\Core\Approvals\ApprovalAccess;
use App\Core\Approvals\Http\Requests\AttachmentFileRequest;
use App\Core\Approvals\Models\ApprovalAttachment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * APR-03: serves an approval attachment on the local media disk through a
 * temporary signed URL (ApprovalPresenter::url; the `signed` middleware
 * checks the signature and expiry). The path names the tenant: the
 * controller enters it, finds the attachment there (row-level security)
 * and checks the user the URL was signed for may still see the request.
 * Always a download, never rendered inline.
 */
class AttachmentFileController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/approvals/([0-9a-f\-]{36})/[0-9a-f\-]{36}\.(pdf|jpg|png|webp|txt|csv|docx|xlsx)\z#';

    public function __invoke(AttachmentFileRequest $request, TenantContext $tenants, ApprovalAccess $access, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $userId, $access) {
            $file = ApprovalAttachment::query()->where('path', $path)->first();
            $approval = $file === null ? null : ApprovalRequest::query()->find($file->request_id);
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

            abort_unless($file !== null && $approval !== null && $user !== null && $user->isActive() && $access->sees($user, $approval), 404);

            $disk = Storage::disk($file->disk);
            abort_unless($disk->exists($file->path), 404);

            return $disk->download($file->path, $file->name, [
                'Content-Type' => $file->mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
