<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-10, MFG-12, MFG-18: one material of a production order: what its
 * version's explosion requires for the planned quantity (base unit, with
 * the part of it that is expected wastage and yield loss), what was
 * issued and returned, and their values once Inventory valued them.
 *
 * @property string $id
 * @property string $order_id
 * @property int $line_no
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty_required_base
 * @property string $expected_loss_base
 * @property string $qty_issued_base
 * @property string $qty_returned_base
 * @property int|string $value_issued_minor
 * @property int|string $value_returned_minor
 */
class OrderMaterial extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'mfg_order_materials';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
