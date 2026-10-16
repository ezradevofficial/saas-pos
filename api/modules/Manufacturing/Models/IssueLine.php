<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-12, MFG-15: one material issued, in a unit of its item and the base
 * unit, what came back of it, and its value and Inventory's cost variance
 * once valued.
 *
 * @property string $id
 * @property string $issue_id
 * @property string $material_id
 * @property int $line_no
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty
 * @property string $qty_base
 * @property string $qty_returned_base
 * @property int|string|null $value_minor
 * @property int|string $cost_variance_minor
 */
class IssueLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'mfg_issue_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(OrderMaterial::class, 'material_id');
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
