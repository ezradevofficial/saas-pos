<?php

namespace Modules\Inventory\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-04, INV-05: stock on hand of an item at a location, in its base unit.
 * Changed only by the ledger, in the movement's transaction.
 *
 * @property string $id
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property string $qty
 */
class Balance extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_balances';

    protected $guarded = [];

    protected $hidden = ['sync_xid', 'sync_seq'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    /** The location's branch, through the location (lists show it). */
    public function branch(): ?Branch
    {
        return $this->location?->branch;
    }
}
