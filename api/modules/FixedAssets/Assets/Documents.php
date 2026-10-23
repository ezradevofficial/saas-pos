<?php

namespace Modules\FixedAssets\Assets;

use App\Core\Audit\Auditor;
use App\Core\Files\TenantFiles;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\AssetDocument;
use Modules\FixedAssets\Support\FaError;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * FA-06: files of an asset on the `media` disk at
 * `tenants/{tenant}/fixed-assets/{asset}/{uuid}.{ext}`, never on a public
 * path; downloaded through the API by holders of the asset's view
 * permission; archived, never deleted (TEN-06). The core file types only
 * (`document_type` otherwise).
 */
class Documents
{
    public const MAX_KB = 10240;

    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function upload(Asset $asset, UploadedFile $file, User $by): AssetDocument
    {
        $mime = (string) $file->getMimeType();
        $extension = self::MIMES[$mime] ?? throw FaError::make('document_type', field: 'file');
        $path = TenantFiles::key($this->tenants->require(), 'fixed-assets', $asset->id, Str::uuid7().'.'.$extension);
        $diskName = TenantFiles::disk();
        $disk = Storage::disk($diskName);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw FaError::make('document_not_stored', status: 500);
        }

        try {
            return AssetDocument::query()->create([
                'asset_id' => $asset->id,
                'name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'disk' => $diskName,
                'path' => $path,
                'uploaded_by' => $by->id,
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    public function archive(AssetDocument $document, User $by): AssetDocument
    {
        $document->fill(['archived_at' => CarbonImmutable::now(), 'archived_by' => $by->id])->save();

        return $document->refresh();
    }

    public function download(AssetDocument $document): StreamedResponse
    {
        $this->auditor->record('fixed_assets.document.download', $document, null, ['asset_id' => $document->asset_id, 'name' => $document->name]);

        return Storage::disk($document->disk)->download($document->path, $document->name, [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
