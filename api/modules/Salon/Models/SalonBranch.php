<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-15, SAL-19: a branch's salon floor (the location whose stock services use) and its public booking page.
 *
 * @property string $company_id
 * @property string $branch_id
 * @property ?string $location_id
 * @property bool $online_enabled
 * @property string $public_code
 * @property ?string $public_name
 * @property int $lead_minutes
 * @property int $max_days_ahead
 */
class SalonBranch extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_branches';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'branch';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['online_enabled' => 'boolean', 'lead_minutes' => 'integer', 'max_days_ahead' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }
}
