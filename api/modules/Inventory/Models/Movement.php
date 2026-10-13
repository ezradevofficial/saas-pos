<?php

namespace Modules\Inventory\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * INV-04: one row of the append-only stock ledger. `qty` is signed, in the
 * item's base unit; `value_minor` is signed, in the company's base
 * currency (`currency`). The database refuses updates and deletes.
 *
 * @property string $id
 * @property string $company_id
 * @property ?string $location_id
 * @property string $item_id
 * @property ?string $batch_id
 * @property string $kind
 * @property string $qty
 * @property int|string $value_minor
 * @property int|string $cost_variance_minor
 * @property string $currency
 * @property string $source_type
 * @property string $source_id
 * @property ?string $source_line_id
 * @property ?string $reverses_movement_id
 * @property list<array{code: string}> $flags
 * @property CarbonImmutable $occurred_at
 */
class Movement extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const OPENING = 'opening';

    public const OPENING_REVERSAL = 'opening_reversal';

    public const RECEIPT = 'receipt';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const COUNT_GAIN = 'count_gain';

    public const COUNT_LOSS = 'count_loss';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_LOSS = 'transfer_loss';

    public const SALE = 'sale';

    public const SALE_RETURN = 'sale_return';

    public const SALE_VOID = 'sale_void';

    public const KINDS = [
        self::OPENING, self::OPENING_REVERSAL, self::RECEIPT, self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::COUNT_GAIN, self::COUNT_LOSS,
        self::TRANSFER_OUT, self::TRANSFER_IN, self::TRANSFER_LOSS, self::SALE, self::SALE_RETURN, self::SALE_VOID,
    ];

    /** Moves between locations of one company: the company's cost does not change. */
    public const TRANSFERS = [self::TRANSFER_OUT, self::TRANSFER_IN];

    protected $table = 'inventory_movements';

    /** Microseconds kept (timestamptz(6)): what moved since a count's freeze is told apart (H2). */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['flags' => 'array', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        // INV-04: the ledger is append-only (the database refuses it too).
        static::updating(fn () => throw new LogicException('The stock ledger is append-only.'));
        static::deleting(fn () => throw new LogicException('The stock ledger is append-only.'));
    }

    public function scope(): Scope
    {
        return $this->location_id === null ? Scope::company($this->company_id) : Scope::location($this->location_id);
    }

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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function serials(): BelongsToMany
    {
        return $this->belongsToMany(Serial::class, 'inventory_movement_serials', 'movement_id', 'serial_id');
    }

    /** @return list<string> */
    public function flagCodes(): array
    {
        return array_values(array_map(fn ($flag) => (string) ($flag['code'] ?? ''), $this->flags ?? []));
    }
}
