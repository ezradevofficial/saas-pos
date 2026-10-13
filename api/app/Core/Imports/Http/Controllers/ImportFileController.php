<?php

namespace App\Core\Imports\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Imports\Http\Requests\ImportFileRequest;
use App\Core\Imports\Import;
use App\Core\Imports\ImportAccess;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * IMP-03: serves an import's error or result file through a temporary
 * signed URL bound to one user (ImportFiles::url). The path names the
 * tenant: the controller enters it, finds the import there (RLS, so
 * another tenant's file is never reached) and checks the signed-for user
 * is the uploader and may still see it (ImportAccess::files). Anything else
 * is not found.
 */
class ImportFileController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/imports/([0-9a-f\-]{36})/(errors|result)\.xlsx\z#';

    public function __invoke(ImportFileRequest $request, TenantContext $tenants, ImportAccess $access, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]) && Str::isUuid($parts[2]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $parts, $userId, $access) {
            $import = Import::query()->find($parts[2]);
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;
            $stored = $parts[3] === 'errors' ? $import?->error_file_path : $import?->result_file_path;

            abort_unless($import !== null && $stored === $path && $user !== null && $user->isActive() && $access->files($user, $import), 404);

            $disk = Storage::disk($import->file_disk);
            abort_unless($disk->exists($path), 404);
            $name = pathinfo($import->file_name, PATHINFO_FILENAME)."-{$parts[3]}.xlsx";

            return $disk->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, max-age=0, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
