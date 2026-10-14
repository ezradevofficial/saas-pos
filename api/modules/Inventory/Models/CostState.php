<?php

namespace Modules\Inventory\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-12: what a company holds of an item (all its locations and what is in
 * transit) and its value in the base currency, plus the last receipt's cost
 * and quantity (its exact unit cost). Changed only by the ledger under the
 * row's lock.
 *
 * @property string $id
 * @property string $company_id
 * @property string $item_id
 * @property string $qty
 * @property int|string $value_minor
 * @property string $currency
 * @property int|string|null $last_cost_minor
 * @property ?string $last_cost_qty
 */
class CostState extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_cost_states';

    protected $guarded = [];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
