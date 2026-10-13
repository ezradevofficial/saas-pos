<?php

namespace Modules\Inventory\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-08: one item of a transfer: the quantity asked (in its unit and the
 * base unit), its batch and serials, and what arrived and was lost.
 *
 * @property string $id
 * @property string $transfer_id
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty
 * @property string $qty_base
 * @property ?string $batch_id
 * @property list<string> $serials
 * @property ?string $qty_received
 * @property ?string $qty_lost
 */
class TransferLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_transfer_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['serials' => 'array', 'line_no' => 'integer'];
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
