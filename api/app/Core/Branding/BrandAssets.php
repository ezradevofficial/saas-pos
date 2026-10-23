<?php

namespace App\Core\Branding;

use App\Core\Audit\Auditor;
use App\Core\Branding\Models\BrandAsset;
use App\Core\Files\TenantFiles;
use App\Core\Http\ApiException;
use App\Core\Http\SignedUrls;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * BR-02, BR-04: logos, favicons and sign-in backgrounds on the tenant
 * files disk (TenantFiles: local in development, Linode Object Storage in
 * production) at `tenants/{tenant}/branding/{uuid}.{ext}` (JPEG, PNG or
 * WebP, as item images). Uploading is audited as `core.branding.asset_add`.
 *
 * Branding is public (the sign-in page shows it before anyone signs in),
 * but the bucket never is (NFR-07): url() signs a route
 * (`GET branding/assets/{path}`, BrandAssetFileController) that streams
 * the file from whichever disk holds it, with cache headers. The expiry is
 * rounded to the day, so the URL of a file stays the same for a day and
 * browsers and proxies can cache it (a presigned object-store URL changes
 * every second and would not cache).
 */
class BrandAssets
{
    public const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function add(string $kind, UploadedFile $file, ?User $by): BrandAsset
    {
        $mime = (string) $file->getMimeType();
        [$width, $height] = getimagesize($file->getRealPath()) ?: [0, 0];
        $path = TenantFiles::key($this->tenants->require(), 'branding', Str::uuid7().'.'.self::EXTENSIONS[$mime]);
        $diskName = TenantFiles::disk();
        $disk = Storage::disk($diskName);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw new ApiException(500, 'asset_not_stored', __('branding.errors.asset_not_stored'));
        }

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($kind, $diskName, $path, $mime, $file, $width, $height, $by) {
                $asset = BrandAsset::create([
                    'kind' => $kind,
                    'disk' => $diskName,
                    'path' => $path,
                    'mime' => $mime,
                    'size' => (int) $file->getSize(),
                    'width' => (int) $width,
                    'height' => (int) $height,
                    'created_by' => $by?->id,
                ]);

                $this->auditor->record('core.branding.asset_add', $asset, null, [
                    'kind' => $kind, 'mime' => $mime, 'size' => $asset->size, 'width' => $asset->width, 'height' => $asset->height,
                ]);

                return $asset;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    public function url(BrandAsset $asset): string
    {
        $expires = CarbonImmutable::now()->startOfDay()->addDays(2);

        return SignedUrls::temporary('branding.asset', $expires, ['path' => $asset->path]);
    }

    /** The signed URL of the file at $path (a path the public lookup returned), or null. */
    public function urlForPath(?string $path): ?string
    {
        if ($path === null || preg_match(BrandAssetPath::PATTERN, $path) !== 1) {
            return null;
        }

        return $this->url(new BrandAsset(['path' => $path]));
    }

    /**
     * The signed URLs of the assets a theme names (logos, favicon, sign-in
     * background), in the current tenant.
     *
     * @return array{logo_light: ?string, logo_dark: ?string, favicon: ?string, background: ?string}
     */
    public function urlsFor(array $theme): array
    {
        $ids = [
            'logo_light' => $theme['logo_light'] ?? null,
            'logo_dark' => $theme['logo_dark'] ?? null,
            'favicon' => $theme['favicon'] ?? null,
            'background' => is_array($theme['login'] ?? null) ? ($theme['login']['background'] ?? null) : null,
        ];
        $valid = array_values(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id)));
        $assets = $valid === [] ? collect() : BrandAsset::query()->whereIn('id', $valid)->get()->keyBy('id');

        return array_map(fn ($id) => is_string($id) && $assets->has($id) ? $this->url($assets[$id]) : null, $ids);
    }
}
