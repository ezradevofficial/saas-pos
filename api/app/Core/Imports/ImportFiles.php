<?php

namespace App\Core\Imports;

use App\Core\Files\TenantFiles;
use App\Core\Http\SignedUrls;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * IMP-01, IMP-03: import files on the tenant files disk (TenantFiles;
 * Linode Object Storage in production), under the tenant:
 * `tenants/{tenant}/imports/{import}/{source.csv|source.xlsx|errors.xlsx|result.xlsx}`,
 * never on a public path. Every file of an import is on the disk its row
 * names (`file_disk`). Downloads go through a temporary signed route bound
 * to the user, which enters the tenant the path names, checks the user
 * again and streams the file (ImportFileController).
 */
class ImportFiles
{
    public const URL_MINUTES = 15;

    public function __construct(private readonly TenantContext $tenants) {}

    public function directory(string $importId): string
    {
        return TenantFiles::key($this->tenants->require(), 'imports', $importId);
    }

    public function storeSource(string $disk, UploadedFile $file, string $importId, string $format): string
    {
        $path = $this->directory($importId)."/source.{$format}";

        if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path))) {
            throw new RuntimeException('The import file could not be stored.');
        }

        return $path;
    }

    /** Store a file written locally (a result or error file) and remove the local copy. */
    public function storeLocal(string $disk, string $localPath, string $importId, string $name): string
    {
        $path = $this->directory($importId).'/'.$name;
        $stream = fopen($localPath, 'r');

        try {
            if ($stream === false || ! Storage::disk($disk)->writeStream($path, $stream)) {
                throw new RuntimeException('The import file could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($localPath);
        }

        return $path;
    }

    /**
     * A local path to read the stored file from: the disk's own path when
     * local, else a temporary copy (removed by $cleanup).
     *
     * @return array{0: string, 1: callable(): void}
     */
    public function local(string $diskName, string $path): array
    {
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return [$disk->path($path), fn () => null];
        }

        // tempnam creates the file it names: the copy (with the extension the
        // reader needs) sits beside it, and both go when done.
        $base = tempnam(sys_get_temp_dir(), 'import-');
        $copy = $base.'.'.pathinfo($path, PATHINFO_EXTENSION);
        $stream = $disk->readStream($path);
        file_put_contents($copy, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return [$copy, function () use ($base, $copy) {
            @unlink($copy);
            @unlink($base);
        }];
    }

    /** A temporary download URL for $user. */
    public function url(string $path, User $user, string $downloadName): string
    {
        $expires = now()->addMinutes(self::URL_MINUTES);

        return SignedUrls::temporary('imports.file', $expires, ['path' => $path, 'user' => $user->id]);
    }
}
