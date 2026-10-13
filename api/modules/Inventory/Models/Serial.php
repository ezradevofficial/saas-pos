<?php

namespace Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-07: one serial number of an item, where it is and whether it is in
 * stock, in transit or issued. Moved only by the ledger.
 *
 * @property string $id
 * @property string $item_id
 * @property string $serial_number
 * @property string $status
 * @property ?string $location_id
 */
class Serial extends Model
{
    use BelongsToTenant, HasUuids;

    public const IN_STOCK = 'in_stock';

    public const IN_TRANSIT = 'in_transit';

    public const ISSUED = 'issued';

    protected $table = 'inventory_serials';

    protected $fillable = ['item_id', 'serial_number', 'status', 'location_id'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
