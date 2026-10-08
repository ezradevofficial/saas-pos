<?php

namespace App\Core\Sync\Http\Controllers;

use App\Core\MasterData\Items\ItemImage;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\Http\Requests\SyncMediaRequest;
use App\Core\Sync\Sources\ItemSource;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * MD-02, NFR-04: an item image for a device, with its own token: found
 * under row-level security and only for an item the device may hold (its
 * company's or shared, active). Anything else is not found. The device
 * caches it until the item's images change.
 */
class SyncMediaController
{
    public function __invoke(SyncMediaRequest $request, ItemSource $items, string $itemImage): Response
    {
        $image = ItemImage::query()->whereKey($itemImage)->first();
        abort_if($image === null, 404);

        $scope = DeviceScope::of($request->device());
        $visible = $items->visible(DB::connection(TenantContext::CONNECTION)->table('items'), $scope)
            ->where('items.id', $image->item_id)
            ->whereNull('items.archived_at')
            ->exists();
        abort_unless($visible, 404);

        $disk = Storage::disk($image->disk);
        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, null, [
            'Content-Type' => $image->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
