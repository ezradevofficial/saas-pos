<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-10, MFG-13: what a production order is to give: its product and the
 * by-products of its version (planned and reported, base unit), each
 * by-product with its share of a report's cost.
 *
 * @property string $id
 * @property string $order_id
 * @property int $line_no
 * @property string $item_id
 * @property string $kind
 * @property string $qty_planned_base
 * @property string $qty_reported_base
 * @property string $cost_share_percent
 */
class OrderOutput extends Model
{
    use BelongsToTenant, HasUuids;

    public const PRODUCT = 'product';

    public const BY_PRODUCT = 'by_product';

    protected $table = 'mfg_order_outputs';

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
}
