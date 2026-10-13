<?php

namespace Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-06: stock of one batch of an item at a location. Changed only by the ledger.
 *
 * @property string $location_id
 * @property string $item_id
 * @property string $batch_id
 * @property string $qty
 */
class BatchBalance extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_batch_balances';

    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
