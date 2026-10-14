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
 * STR-06: goods back to the store against an issue, through the issue's
 * ledger. Audited as `stores.return.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $issue_id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $department_id
 * @property ?string $cost_centre_id
 * @property string $ledger
 * @property string $reason
 * @property string $returned_by
 * @property ?string $currency
 */
class StoreReturn extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const REASONS = ['unused', 'excess', 'wrong_item', 'damaged', 'other'];

    protected $table = 'stores_returns';

    protected string $auditModule = 'stores';

    protected string $auditResource = 'return';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['returned_at' => 'immutable_datetime', 'valued_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class, 'return_id')->orderBy('line_no');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
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

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
