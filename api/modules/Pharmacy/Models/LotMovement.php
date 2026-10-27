<?php

namespace Modules\Pharmacy\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-05: one row of the append-only lot ledger. `qty` is signed, in the
 * item's base unit. The database refuses updates and deletes.
 *
 * @property string $id
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property string $lot_id
 * @property string $kind
 * @property string $qty
 * @property string $source_type
 * @property ?string $source_id
 * @property ?string $source_line_id
 * @property ?string $sale_id
 * @property ?string $reference
 * @property ?string $customer_id
 * @property list<string> $flags
 * @property CarbonImmutable $occurred_at
 */
class LotMovement extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const RECEIPT = 'receipt';

    public const SALE = 'sale';

    public const SALE_VOID = 'sale_void';

    public const SALE_RETURN = 'sale_return';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const WRITE_OFF = 'write_off';

    public const ADJUSTMENT = 'adjustment';

    protected $table = 'pharmacy_lot_movements';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
