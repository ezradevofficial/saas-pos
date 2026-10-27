<?php

namespace Modules\Pharmacy\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-05: stock of one lot at one location, in the item's base unit.
 * Changed only by the lot ledger (Lots::move), never directly.
 *
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property string $lot_id
 * @property string $qty
 */
class LotBalance extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pharmacy_lot_balances';

    protected $guarded = ['id', 'tenant_id'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
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
