<?php

namespace App\Core\Sync\Http\Controllers;

use App\Core\Branding\Models\BrandAsset;
use App\Core\Sync\Http\Requests\SyncBrandAssetRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-02, LAY-05, NFR-04: a brand asset (a logo, or an image a POS layout
 * puts on a category tile) for a device, with its own token: found under
 * row-level security, so only the device's tenant's (TEN-01). Brand assets
 * are shown on the tenant's public sign-in page already, so any of the
 * tenant's logos and images may reach its tills; favicons are not served.
 * The till keeps a copy for offline use (ADR 004).
 */
class SyncBrandAssetController
{
    public function __invoke(SyncBrandAssetRequest $request, string $brandAsset): Response
    {
        abort_unless(Str::isUuid($brandAsset), 404);

        $asset = BrandAsset::query()->whereKey($brandAsset)->where('kind', '!=', BrandAsset::FAVICON)->first();
        abort_if($asset === null, 404);

        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->path), 404);

        return $disk->response($asset->path, null, [
            'Content-Type' => $asset->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
