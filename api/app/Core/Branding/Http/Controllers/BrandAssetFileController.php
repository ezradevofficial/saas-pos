<?php

namespace App\Core\Branding\Http\Controllers;

use App\Core\Branding\BrandAssetPath;
use App\Core\Branding\Http\Requests\PublicHostRequest;
use App\Core\Branding\Models\BrandAsset;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-02, BR-04: serves a brand asset on the local media disk through a
 * signed URL (BrandAssets::url; the `signed` middleware checks the
 * signature and expiry). Branding is public, so no user is needed, but the
 * file is still only served when the path names a tenant that has this
 * asset: the controller enters that tenant and finds the row under
 * row-level security. Anything else is not found.
 */
class BrandAssetFileController
{
    public function __invoke(PublicHostRequest $request, TenantContext $tenants, string $path): Response
    {
        abort_unless(preg_match(BrandAssetPath::PATTERN, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);

        return $tenants->run($parts[1], function () use ($path) {
            $asset = BrandAsset::query()->where('path', $path)->first();
            abort_unless($asset !== null, 404);

            $disk = Storage::disk($asset->disk);
            abort_unless($disk->exists($asset->path), 404);

            return $disk->response($asset->path, null, [
                'Content-Type' => $asset->mime,
                'Cache-Control' => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'",
            ]);
        });
    }
}
