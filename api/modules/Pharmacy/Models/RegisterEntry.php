<?php

namespace Modules\Pharmacy\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-18: one entry of the controlled-drug register (append-only; a trigger
 * refuses update and delete). `qty` is signed, `balance` the item's balance
 * at the location after the entry.
 *
 * @property string $id
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property int $entry_no
 * @property string $kind
 * @property string $qty
 * @property string $balance
 * @property ?string $lot_id
 * @property ?string $batch_number
 * @property ?CarbonImmutable $expires_on
 * @property ?string $prescription_id
 * @property ?string $prescriber_name
 * @property ?string $prescriber_licence
 * @property ?string $patient_name
 * @property ?string $pharmacist_id
 * @property ?string $recorded_by
 * @property string $source_type
 * @property ?string $source_id
 * @property ?string $reason
 * @property CarbonImmutable $occurred_at
 */
class RegisterEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const RECEIPT = 'receipt';

    public const DISPENSE = 'dispense';

    public const RETURN = 'return';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const WRITE_OFF = 'write_off';

    public const ADJUSTMENT = 'adjustment';

    public const KINDS = [self::RECEIPT, self::DISPENSE, self::RETURN, self::TRANSFER_OUT, self::TRANSFER_IN, self::WRITE_OFF, self::ADJUSTMENT];

    protected $table = 'pharmacy_register_entries';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['entry_no' => 'integer', 'expires_on' => 'immutable_date', 'occurred_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacist_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
