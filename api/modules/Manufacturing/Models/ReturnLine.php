<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-12: one material given back against an issue line, and its value
 * once Inventory valued it.
 *
 * @property string $id
 * @property string $return_id
 * @property string $issue_line_id
 * @property int $line_no
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty
 * @property string $qty_base
 * @property int|string|null $value_minor
 * @property int|string $cost_variance_minor
 */
class ReturnLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'mfg_return_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }

    public function issueLine(): BelongsTo
    {
        return $this->belongsTo(IssueLine::class);
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
