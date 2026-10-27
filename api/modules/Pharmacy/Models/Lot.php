<?php

namespace Modules\Pharmacy\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PHA-04: a lot (batch) of an item at a company: batch number (unique per
 * item ignoring case), manufacture and expiry dates, and a status. A lot
 * is never deleted. It is blocked when quarantined or under an open recall.
 *
 * @property string $id
 * @property string $company_id
 * @property string $item_id
 * @property string $batch_number
 * @property ?CarbonImmutable $manufactured_on
 * @property CarbonImmutable $expires_on
 * @property ?string $supplier_id
 * @property string $status
 * @property ?string $quarantine_reason
 * @property ?string $recall_id
 */
class Lot extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const QUARANTINED = 'quarantined';

    protected $table = 'pharmacy_lots';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'manufactured_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'quarantined_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function recall(): BelongsTo
    {
        return $this->belongsTo(Recall::class);
    }

    public function quarantiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quarantined_by');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LotBalance::class, 'lot_id');
    }

    /** PHA-11, PHA-22: quarantined or under an open recall. */
    public function isBlocked(): bool
    {
        return $this->status !== self::ACTIVE || $this->recall_id !== null;
    }

    /** The reason a blocked lot is blocked: `recalled` first, else `quarantined`; null when it is not blocked. */
    public function blockedReason(): ?string
    {
        return match (true) {
            $this->recall_id !== null => 'recalled',
            $this->status === self::QUARANTINED => 'quarantined',
            default => null,
        };
    }
}
