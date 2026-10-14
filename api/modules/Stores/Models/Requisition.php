<?php

namespace Modules\Stores\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
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
 * STR-02 to STR-04: a department's request to a store, decided through the
 * `stores.requisition` flow, then issued in full or in parts. Never
 * deleted; audited as `stores.requisition.*` (STR-17).
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $department_id
 * @property ?string $cost_centre_id
 * @property string $status
 * @property string $requested_by
 * @property ?string $decided_by
 */
class Requisition extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const PARTIALLY_ISSUED = 'partially_issued';

    public const ISSUED = 'issued';

    public const CLOSED = 'closed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::PARTIALLY_ISSUED, self::ISSUED, self::CLOSED, self::REJECTED, self::CANCELLED];

    /** Goods may be issued against it. */
    public const ISSUABLE = [self::APPROVED, self::PARTIALLY_ISSUED];

    protected $table = 'stores_requisitions';

    protected string $auditModule = 'stores';

    protected string $auditResource = 'requisition';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['needed_by' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class)->orderBy('line_no');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class)->orderBy('issued_at')->orderBy('id');
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
