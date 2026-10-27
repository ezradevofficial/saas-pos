<?php

namespace Modules\Pharmacy\Models;

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
 * PHA-12: a write-off of lot stock, a workflow document (`pharmacy.write_off`).
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $reason
 * @property ?string $note
 * @property string $status
 * @property string $requested_by
 * @property ?string $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $posted_at
 * @property ?string $failure
 */
class WriteOff extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::POSTED, self::REJECTED, self::CANCELLED, self::FAILED];

    public const REASONS = ['expired', 'damaged', 'recalled', 'other'];

    protected $table = 'pharmacy_write_offs';

    protected $guarded = ['id', 'tenant_id'];

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
        return $this->hasMany(WriteOffLine::class, 'write_off_id')->orderBy('line_no');
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
