<?php

namespace App\Core\MasterData\Items;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MD-02: a barcode of an item, for its base unit (uom_id null) or one of
 * its other units. Stored normalised (Barcode::normalise). company_id and
 * item_archived_at copy the item's (database triggers) so the unique
 * indexes keep one active item per barcode in the sharing scope (review
 * focus 4). Changes are audited on the item as `core.item.barcodes_update`.
 */
class ItemBarcode extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['item_id', 'uom_id', 'barcode'];
}
