<?php

namespace Modules\Stores\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STR-07, STR-10: one row of the append-only store ledger. `qty` is signed,
 * in the item's base unit; `balance_after` is the store's quantity after
 * it in the own ledger (null when Inventory holds the quantities);
 * `value_minor` is written once, when Inventory valued the movement. The
 * database refuses deletes and every other update.
 *
 * @property string $id
 * @property int $seq
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property string $kind
 * @property string $ledger
 * @property string $qty
 * @property ?string $balance_after
 * @property int|string|null $value_minor
 * @property ?string $currency
 * @property string $source_type
 * @property string $source_id
 * @property ?string $source_line_id
 */
class Movement extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const OPENING = 'opening';

    public const RECEIPT = 'receipt';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const ISSUE = 'issue';

    public const RETURN = 'return';

    public const KINDS = [self::OPENING, self::RECEIPT, self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::ISSUE, self::RETURN];

    /** STR-07: the store's own ledger holds the quantity. */
    public const LEDGER_STORES = 'stores';

    /** STR-08: Inventory holds the quantity; this row records Stores' document. */
    public const LEDGER_INVENTORY = 'inventory';

    public const SOURCE_ENTRY = 'stores.entry';

    public const SOURCE_ISSUE = 'stores.issue';

    public const SOURCE_RETURN = 'stores.return';

    public const SOURCE_GOODS_RECEIPT = 'procurement.goods_receipt';

    protected $table = 'stores_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'seq' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
