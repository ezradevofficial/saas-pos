<?php

namespace App\Core\MasterData\Items;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MD-02: an image of an item, a file on the `media` disk under
 * `tenants/{tenant}/items/{item}/`. Images are files, not business
 * records: deleting one removes the file and the row. Adds, deletes and
 * reorders are audited on the item (`core.item.image_add`,
 * `core.item.image_delete`, `core.item.images_reorder`).
 */
class ItemImage extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['item_id', 'disk', 'path', 'position', 'mime', 'size', 'width', 'height'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
