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
 * INV-10: a stock adjustment at one location with a reason, decided
 * through the `inventory.adjustment` flow and posted on approval. Never
 * deleted; audited as `inventory.adjustment.*` (INV-24).
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $reason
 * @property string $status
 * @property string $requested_by
 */
class Adjustment extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Approved, but stock moved meanwhile so it could not be posted (`failure` says why). */
    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::POSTED, self::REJECTED, self::CANCELLED, self::FAILED];

    public const REASONS = ['damage', 'expiry', 'theft', 'found', 'correction', 'internal_use', 'other'];

    protected $table = 'inventory_adjustments';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'adjustment';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AdjustmentLine::class)->orderBy('line_no');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
