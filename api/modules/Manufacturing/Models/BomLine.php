<?php

namespace Modules\Manufacturing\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFG-04: a component (with its yield, expected wastage and whether its own
 * BOM is exploded in its place) or a by-product (with its share of the
 * batch's cost) of a BOM version, in a unit of its item and the base unit
 * (the line's own factor, fixed when it is saved).
 *
 * @property string $id
 * @property string $bom_version_id
 * @property int $line_no
 * @property string $kind
 * @property string $item_id
 * @property string $uom_id
 * @property string $qty
 * @property string $qty_base
 * @property string $yield_percent
 * @property string $wastage_percent
 * @property bool $explode
 * @property string $cost_share_percent
 * @property ?string $import_id
 */
class BomLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const COMPONENT = 'component';

    public const BY_PRODUCT = 'by_product';

    public const KINDS = [self::COMPONENT, self::BY_PRODUCT];

    protected $table = 'mfg_bom_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'explode' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'bom_version_id');
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
