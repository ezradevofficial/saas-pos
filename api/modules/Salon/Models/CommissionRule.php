<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-20: an effective-dated commission rule. A rule names a staff member or all, a service or all (services), or
 * product sales; the most specific live rule wins.
 *
 * @property string $company_id
 * @property ?string $staff_id
 * @property ?string $service_id
 * @property string $applies_to
 * @property string $kind
 * @property ?string $rate
 * @property ?int $amount_minor
 * @property ?string $currency
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 */
class CommissionRule extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_commission_rules';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'commission_rule';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
