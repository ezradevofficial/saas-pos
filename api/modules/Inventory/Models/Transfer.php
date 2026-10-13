<?php

namespace Modules\Inventory\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * INV-08: a transfer of stock between two locations of one company,
 * decided through the `inventory.transfer` flow, then dispatched (in
 * transit) and received. Its place for permissions is the source
 * location (HasScope); receiving is checked at the destination. Never
 * deleted; every change audited as `inventory.transfer.*` (INV-24).
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $from_location_id
 * @property string $to_location_id
 * @property string $status
 * @property string $requested_by
 */
class Transfer extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const IN_TRANSIT = 'in_transit';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::IN_TRANSIT, self::RECEIVED, self::CANCELLED];

    protected $table = 'inventory_transfers';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'transfer';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->from_location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class)->orderBy('line_no');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
