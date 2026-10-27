<?php

namespace Modules\Pharmacy\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-12: one lot and quantity (base unit) of a write-off.
 *
 * @property string $write_off_id
 * @property int $line_no
 * @property string $item_id
 * @property string $lot_id
 * @property string $qty
 */
class WriteOffLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pharmacy_write_off_lines';

    protected $guarded = ['id', 'tenant_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
