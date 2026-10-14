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
 * STR-04, STR-05: goods handed from a store to a department against a
 * requisition, moved by the store's own ledger or by Inventory (`ledger`),
 * then acknowledged by the department. Audited as `stores.issue.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $requisition_id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $department_id
 * @property ?string $cost_centre_id
 * @property string $ledger
 * @property string $status
 * @property string $issued_by
 * @property ?string $currency
 */
class Issue extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const ISSUED = 'issued';

    public const ACKNOWLEDGED = 'acknowledged';

    public const STATUSES = [self::ISSUED, self::ACKNOWLEDGED];

    protected $table = 'stores_issues';

    protected string $auditModule = 'stores';

    protected string $auditResource = 'issue';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'valued_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IssueLine::class)->orderBy('line_no');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(StoreReturn::class)->orderBy('returned_at')->orderBy('id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
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

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
