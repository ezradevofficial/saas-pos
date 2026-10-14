<?php

namespace Modules\Inventory\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-10: one item of an adjustment: a signed quantity (in its unit and
 * the base unit), the batch it touches (or a new batch number with its
 * expiry), serials, a typed unit cost for increases, and the value posted.
 *
 * @property string $id
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty
 * @property string $qty_base
 * @property ?string $batch_id
 * @property ?string $batch_number
 * @property list<string> $serials
 * @property ?string $unit_cost_minor per entered unit, in unit_cost_currency (ADR 003)
 * @property ?string $unit_cost_currency
 */
class AdjustmentLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_adjustment_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['serials' => 'array', 'line_no' => 'integer', 'expires_on' => 'immutable_date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
