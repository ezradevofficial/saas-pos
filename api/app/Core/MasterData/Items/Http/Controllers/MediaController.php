<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Http\Requests\MediaRequest;
use App\Core\MasterData\Items\ItemImage;
use App\Core\MasterData\Items\ItemPolicy;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * MD-02: serves an item image on the local media disk through a temporary
 * signed URL (ItemImages::url; the `signed` middleware checks the
 * signature and expiry). The path names the tenant: the controller enters
 * it, finds the image row there (row-level security) and checks the user
 * the URL was signed for may still view the item. Anything else is not
 * found. Storage paths are never served without this check.
 */
class MediaController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/items/([0-9a-f\-]{36})/[0-9a-f\-]{36}\.(jpg|png|webp)\z#';

    public function __invoke(MediaRequest $request, TenantContext $tenants, ItemPolicy $policy, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $userId, $policy) {
            $image = ItemImage::query()->with('item')->where('path', $path)->first();
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

            abort_unless($image !== null && $image->item !== null && $user !== null && $user->isActive() && $policy->view($user, $image->item), 404);

            $disk = Storage::disk($image->disk);
            abort_unless($disk->exists($image->path), 404);

            return $disk->response($image->path, null, [
                'Content-Type' => $image->mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
