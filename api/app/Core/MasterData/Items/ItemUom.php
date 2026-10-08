<?php

namespace App\Core\MasterData\Items;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MD-02: another unit an item is sold or bought in, with how many base
 * units one of it holds (numeric(18,6), > 0). The base unit (factor 1) is
 * implicit, never a row. Changes are audited on the item as
 * `core.item.units_update` (MD-07).
 */
class ItemUom extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['item_id', 'uom_id', 'factor', 'is_sales_default', 'is_purchase_default'];

    protected function casts(): array
    {
        return ['is_sales_default' => 'boolean', 'is_purchase_default' => 'boolean'];
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
