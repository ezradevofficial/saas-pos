<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-07, MFG-08: one ingredient a sale line consumed (base unit, the
 * recipe version applied, how much came back since) or gave back (naming
 * the consumed line), with its value once Inventory valued it.
 *
 * @property string $id
 * @property string $backflush_id
 * @property int $line_no
 * @property string $sale_line_id
 * @property string $sold_item_id
 * @property string $bom_version_id
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty_base
 * @property string $qty_returned_base
 * @property ?string $origin_line_id
 * @property int|string|null $value_minor
 * @property int|string $cost_variance_minor
 */
class BackflushLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'mfg_backflush_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }

    public function backflush(): BelongsTo
    {
        return $this->belongsTo(Backflush::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function soldItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'sold_item_id');
    }
}
