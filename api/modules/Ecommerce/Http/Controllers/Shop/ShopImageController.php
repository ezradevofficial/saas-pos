<?php

namespace Modules\Ecommerce\Http\Controllers\Shop;

use App\Core\MasterData\Items\ItemImage;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * ECOM-02: an item image on the storefront, behind a temporary signed URL
 * (ShopCatalogue::imageUrl; the signature, checked by the `signed`
 * middleware, is the credential). The signed tenant is entered; the image
 * is served only while its item is a published product of a live store
 * and the module is active.
 */
class ShopImageController
{
    public function __invoke(Request $request, string $image, TenantContext $tenants, ModuleRegistry $modules): Response
    {
        $tenant = (string) $request->query('tenant', '');
        abort_unless(Str::isUuid($tenant) && Str::isUuid($image), 404);

        return $tenants->run($tenant, function () use ($image, $modules) {
            $found = ItemImage::query()->find($image);
            $listed = $found !== null && $modules->isActive(EcommerceServiceProvider::MODULE) && Product::query()->where('item_id', $found->item_id)->where('published', true)->whereNull('archived_at')
                ->whereIn('store_id', Store::query()->where('status', Store::LIVE)->whereNull('archived_at')->select('id'))->exists();
            abort_unless($listed && Storage::disk($found->disk)->exists($found->path), 404);

            return response(Storage::disk($found->disk)->get($found->path), 200, [
                'Content-Type' => $found->mime ?: 'application/octet-stream',
                'Cache-Control' => 'public, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'",
            ]);
        });
    }
}
