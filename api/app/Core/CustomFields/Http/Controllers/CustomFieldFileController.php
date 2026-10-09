<?php

namespace App\Core\CustomFields\Http\Controllers;

use App\Core\CustomFields\CustomFieldAccess;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\CustomFieldFile;
use App\Core\CustomFields\CustomFieldFiles;
use App\Core\CustomFields\Http\Requests\CustomFieldFileDownloadRequest;
use App\Core\CustomFields\Http\Requests\StoreCustomFieldFileRequest;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * CF-01: files of file fields. Upload (the record then stores the id) and
 * the download behind a temporary signed URL (CustomFieldFiles::url): the
 * path names the tenant; the controller enters it, finds the file there
 * (row-level security) and checks the signed-for user may still see the
 * record and the field (an upload not yet saved: only its uploader).
 * Always a download, never rendered inline.
 */
class CustomFieldFileController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/custom-fields/[a-z][a-z0-9_]{0,39}(\.[a-z][a-z0-9_]{0,29})?/[0-9a-f\-]{36}\.(pdf|jpg|png|webp|txt|csv|docx|xlsx)\z#';

    public function store(StoreCustomFieldFileRequest $request, CustomFieldFiles $files): JsonResponse
    {
        $file = $files->upload($request->definition(), $request->user(), $request->file('file'));

        return new JsonResponse(['data' => $files->present($file, $request->user())], 201);
    }

    public function download(CustomFieldFileDownloadRequest $request, TenantContext $tenants, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $userId) {
            $file = CustomFieldFile::query()->where('path', $path)->first();
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

    private function sees(User $user, CustomFieldFile $file): bool
    {
        if ($file->record_id === null) {
            return $file->uploaded_by === $user->id;
        }

        $entity = app(CustomFieldEntities::class)->find($file->entity);
        $record = $entity?->newQuery()->find($file->record_id);

        return $record !== null
            && $entity->view($user, $record)
            && ($record->custom[$file->field_key] ?? null) === $file->id
            && isset(app(CustomFieldDefinitions::class)->byKey($file->entity)[$file->field_key])
            && ! in_array($file->field_key, app(CustomFieldAccess::class)->for($user, $file->entity)['hidden'], true);
    }
}
