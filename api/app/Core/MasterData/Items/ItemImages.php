<?php

namespace App\Core\MasterData\Items;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * MD-02: item images on the `media` disk, at
 * `tenants/{tenant}/items/{item}/{uuid}.{ext}`, at most
 * Item::MAX_IMAGES per item, in `position` order (1, 2, ...). Never served
 * from a public path: readers get a temporary URL, signed by the object
 * store (s3 driver) or, on the local driver, a signed route
 * (`GET media/{path}`, MediaController) bound to the user, which checks the
 * tenant and `core.item.view` again. Every change is audited on the item
 * under the key `images`, the field name field rules hide them by (RBAC-05).
 */
class ItemImages
{
    public const DISK = 'media';

    public const URL_MINUTES = 15;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** @throws ApiException 422 `image_limit` when the item has its maximum already */
    public function add(Item $item, UploadedFile $file): ItemImage
    {
        // Checked first so a full item stores no file, then again under the item's lock.
        $this->assertRoom($item);

        $mime = $file->getMimeType();
        [$width, $height] = getimagesize($file->getRealPath()) ?: [0, 0];
        $path = sprintf('tenants/%s/items/%s/%s.%s', $this->tenants->require(), $item->id, Str::uuid7(), self::EXTENSIONS[$mime]);
        $disk = Storage::disk(self::DISK);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw new ApiException(500, 'image_not_stored', __('core.item.image_not_stored'));
        }

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item, $path, $mime, $file, $width, $height) {
                Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $count = $this->assertRoom($item);

                $image = ItemImage::create([
                    'item_id' => $item->id,
                    'disk' => self::DISK,
                    'path' => $path,
                    'position' => $count + 1,
                    'mime' => $mime,
                    'size' => $file->getSize(),
                    'width' => $width,
                    'height' => $height,
                ]);

                $this->auditor->record('core.item.image_add', $item, null, ['images' => [$this->summary($image)]]);

                return $image;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** Delete the row (positions closed up), then the file once committed. */
    public function delete(ItemImage $image): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($image) {
            $item = Item::query()->whereKey($image->item_id)->lockForUpdate()->firstOrFail();
            $image = ItemImage::query()->whereKey($image->id)->firstOrFail();
            $image->delete();

            ItemImage::query()->where('item_id', $item->id)->orderBy('position')->get()
                ->each(function (ItemImage $other, int $index) {
                    if ($other->position !== $index + 1) {
                        $other->update(['position' => $index + 1]);
                    }
                });

            $this->auditor->record('core.item.image_delete', $item, ['images' => [$this->summary($image)]], null);

            DB::connection(TenantContext::CONNECTION)->afterCommit(function () use ($image) {
                try {
                    Storage::disk($image->disk)->delete($image->path);
                } catch (Throwable $e) {
                    // The row is gone; a stray file is reported, never served.
                    report($e);
                }
            });
        });
    }

    /**
     * Put the item's images in the order of $ids, which must name every one
     * of them once.
     *
     * @param  list<string>  $ids
     */
    public function reorder(Item $item, array $ids): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item, $ids) {
            Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $images = ItemImage::query()->where('item_id', $item->id)->orderBy('position')->get()->keyBy('id');
            $before = $images->keys()->values()->all();

            if (count($ids) !== $images->count() || array_diff($ids, $before) !== []) {
                throw new ApiException(422, 'image_order_invalid', __('core.item.image_order_invalid'), [
                    'image_ids' => [__('core.item.image_order_invalid')],
                ]);
            }

            if ($ids === $before) {
                return;
            }

            foreach (array_values($ids) as $index => $id) {
                $images[$id]->update(['position' => $index + 1]);
            }

            $this->auditor->record('core.item.images_reorder', $item, ['images' => $before], ['images' => array_values($ids)]);
        });
    }

    /**
     * A temporary URL for $user: the object store's own on s3, else the
     * signed media route bound to the user.
     */
    public function url(ItemImage $image, User $user): string
    {
        $expires = now()->addMinutes(self::URL_MINUTES);

        if (config("filesystems.disks.{$image->disk}.driver") === 's3') {
            return Storage::disk($image->disk)->temporaryUrl($image->path, $expires);
        }

        return URL::temporarySignedRoute('media.show', $expires, ['path' => $image->path, 'user' => $user->id]);
    }

    /** @return int the item's image count, below the maximum */
    private function assertRoom(Item $item): int
    {
        $count = ItemImage::query()->where('item_id', $item->id)->count();

        if ($count >= Item::MAX_IMAGES) {
            $message = __('core.item.image_limit', ['max' => Item::MAX_IMAGES]);

            throw new ApiException(422, 'image_limit', $message, ['image' => [$message]]);
        }

        return $count;
    }

    /** @return array<string, mixed> */
    private function summary(ItemImage $image): array
    {
        return ['id' => $image->id, 'position' => $image->position, 'mime' => $image->mime, 'size' => $image->size];
    }
}
