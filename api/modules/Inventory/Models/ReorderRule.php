<?php

namespace Modules\Inventory\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-11: the reorder level and quantity of an item at a location.
 * `low_since` is set when stock fell to the level (the alert went out) and
 * cleared when it rose above it. Archived, never deleted; audited as
 * `inventory.reorder_rule.*`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property string $reorder_level
 * @property string $reorder_qty
 * @property ?CarbonImmutable $low_since
 */
class ReorderRule extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'inventory_reorder_rules';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'reorder_rule';

    protected $fillable = ['company_id', 'location_id', 'item_id', 'reorder_level', 'reorder_qty', 'low_since'];

    /** low_since moves with stock, not with people: never audited. */
    protected array $auditHidden = ['low_since'];

    protected function casts(): array
    {
        return ['low_since' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
