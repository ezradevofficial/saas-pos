<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-13, MFG-16: one output of a report (the product, a by-product or
 * scrap) in the item's base unit, with its cost once costed (scrap: none)
 * and Inventory's cost variance once received.
 *
 * @property string $id
 * @property string $report_id
 * @property ?string $output_id
 * @property int $line_no
 * @property string $kind
 * @property string $item_id
 * @property string $qty_base
 * @property int|string|null $cost_minor
 * @property int|string $cost_variance_minor
 */
class ReportLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const PRODUCT = 'product';

    public const BY_PRODUCT = 'by_product';

    public const SCRAP = 'scrap';

    protected $table = 'mfg_report_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(OrderOutput::class, 'output_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
