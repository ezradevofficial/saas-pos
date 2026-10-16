<?php

namespace Modules\Manufacturing\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MFG-10 to MFG-16: a production order at a location: a planned quantity
 * of an item from a fixed BOM version, released (approved where the
 * company asks), its materials issued and returned, its output reported
 * in parts, then closed. `ledger` (fixed at release) says whether
 * Inventory moves and values its stock (`inventory`) or it is
 * quantity-only (`none`). Amounts are minor units of `currency` (the
 * company's base currency). Audited as `manufacturing.order.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $item_id
 * @property string $bom_id
 * @property string $bom_version_id
 * @property string $qty
 * @property string $uom_id
 * @property string $qty_base
 * @property string $status
 * @property ?string $ledger
 * @property int|string $labour_rate_minor
 * @property int|string $overhead_rate_minor
 * @property ?string $currency
 * @property string $qty_good_base
 * @property string $qty_scrap_base
 * @property int|string $material_cost_minor
 * @property int|string $material_relieved_minor
 * @property int|string $labour_cost_minor
 * @property int|string $overhead_cost_minor
 * @property int|string $relieved_minor
 * @property int|string|null $variance_minor
 * @property string $planned_by
 * @property array<string, mixed>|null $custom
 */
class Order extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const PLANNED = 'planned';

    public const PENDING_APPROVAL = 'pending_approval';

    public const RELEASED = 'released';

    public const IN_PROGRESS = 'in_progress';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PLANNED, self::PENDING_APPROVAL, self::RELEASED, self::IN_PROGRESS, self::CLOSED, self::CANCELLED, self::REJECTED];

    /** MFG-12, MFG-13: materials move and production is reported only while the order runs. */
    public const RUNNING = [self::RELEASED, self::IN_PROGRESS];

    /** MFG-18, MFG-19: the orders the consumption and yield reports cover. */
    public const RUNNING_OR_CLOSED = [self::RELEASED, self::IN_PROGRESS, self::CLOSED];

    public const LEDGER_INVENTORY = 'inventory';

    public const LEDGER_NONE = 'none';

    protected $table = 'mfg_orders';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'order';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'custom' => 'array', 'planned_start' => 'date:Y-m-d', 'planned_end' => 'date:Y-m-d',
            'released_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function isValued(): bool
    {
        return $this->ledger === self::LEDGER_INVENTORY;
    }

    public function materials(): HasMany
    {
        return $this->hasMany(OrderMaterial::class)->orderBy('line_no');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(OrderOutput::class)->orderBy('line_no');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class)->orderBy('issued_at')->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(MaterialReturn::class)->orderBy('returned_at')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class)->orderBy('reported_at')->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'bom_version_id');
    }

    public function planner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planned_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
