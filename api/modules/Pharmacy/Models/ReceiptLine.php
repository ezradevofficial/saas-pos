<?php

namespace Modules\Pharmacy\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-06: one lot received. `entered_qty` is in `uom_id`, `qty` in the
 * item's base unit.
 *
 * @property string $receipt_id
 * @property int $line_no
 * @property string $item_id
 * @property string $lot_id
 * @property string $uom_id
 * @property string $entered_qty
 * @property string $qty
 * @property int|string|null $unit_cost_minor
 * @property ?string $currency
 */
class ReceiptLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pharmacy_receipt_lines';

    protected $guarded = ['id', 'tenant_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
