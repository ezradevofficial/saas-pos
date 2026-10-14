<?php

namespace Modules\Stores\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STR-04, STR-06, STR-09: one item issued against a requisition line, in
 * the line's unit and the base unit, what came back of it (base unit), and
 * its value once Inventory valued it.
 *
 * @property string $id
 * @property string $issue_id
 * @property string $requisition_line_id
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty
 * @property string $qty_base
 * @property string $qty_returned_base
 * @property int|string|null $value_minor
 */
class IssueLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'stores_issue_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(RequisitionLine::class);
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
