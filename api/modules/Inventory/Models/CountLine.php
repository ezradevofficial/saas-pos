<?php

namespace Modules\Inventory\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-09: one item (and batch) of a count: expected at the freeze, counted,
 * and the variance with its value once submitted.
 *
 * @property string $id
 * @property string $count_id
 * @property string $item_id
 * @property ?string $batch_id
 * @property string $expected_qty
 * @property ?string $counted_qty
 * @property ?string $variance_qty
 * @property int|string|null $variance_value_minor
 */
class CountLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_count_lines';

    protected $guarded = [];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
