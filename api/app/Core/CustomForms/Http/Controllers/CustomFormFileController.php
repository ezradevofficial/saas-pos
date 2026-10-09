<?php

namespace App\Core\CustomForms\Http\Controllers;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormAttachment;
use App\Core\CustomForms\CustomFormFiles;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomFormType;
use App\Core\CustomForms\Http\Requests\CustomFormFileDownloadRequest;
use App\Core\CustomForms\Http\Requests\StoreCustomFormAttachmentRequest;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * CF-04: attachments of custom form records. Upload (the record then
 * names the id), and the download behind a temporary signed URL: the path
 * names the tenant; the controller enters it, finds the file there
 * (row-level security) and checks the signed-for user may still see the
 * record (an upload not yet saved: only its uploader). Always a download.
 */
class CustomFormFileController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/custom-forms/[a-z][a-z0-9_]{0,29}/[0-9a-f\-]{36}\.(pdf|jpg|png|webp|txt|csv|docx|xlsx)\z#';

    public function store(StoreCustomFormAttachmentRequest $request, CustomFormType $customFormType, CustomFormFiles $files): JsonResponse
    {
        $file = $files->upload($customFormType, $request->user(), $request->file('file'));

        return new JsonResponse(['data' => $files->present($file, $request->user())], 201);
    }

    public function download(CustomFormFileDownloadRequest $request, TenantContext $tenants, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $userId) {
            $file = CustomFormAttachment::query()->where('path', $path)->first();
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

            abort_unless($file !== null && $user !== null && $user->isActive() && $this->sees($user, $file), 404);

            $disk = Storage::disk($file->disk);
            abort_unless($disk->exists($file->path), 404);

            return $disk->download($file->path, $file->name, [
                'Content-Type' => $file->mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    private function sees(User $user, CustomFormAttachment $file): bool
    {
        if ($file->record_id === null) {
            return $file->uploaded_by === $user->id;
        }

        $record = CustomFormRecord::query()->find($file->record_id);

        return $record !== null && app(CustomFormAccess::class)->view($user, $record);
    }
}
