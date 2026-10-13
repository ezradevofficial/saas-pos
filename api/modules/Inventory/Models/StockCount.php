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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * INV-09: a full or cycle stock count at one location. While `counting`
 * the location is frozen for its items; a submitted count with a variance
 * runs the `inventory.count` flow and posts on approval. Never deleted;
 * audited as `inventory.count.*` (INV-24).
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $kind
 * @property string $status
 * @property string $requested_by
 * @property CarbonImmutable $frozen_at
 */
class StockCount extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const COUNTING = 'counting';

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::COUNTING, self::PENDING, self::POSTED, self::REJECTED, self::CANCELLED];

    public const FULL = 'full';

    public const CYCLE = 'cycle';

    protected $table = 'inventory_counts';

    /** Microseconds kept on the freeze (timestamptz(6)), as on movements (H2). */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'count';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'frozen_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CountLine::class, 'count_id');
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
