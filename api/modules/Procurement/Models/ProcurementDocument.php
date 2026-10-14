<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Procurement document at a branch of a company (RBAC-04: checked at the
 * branch), audited as `procurement.{resource}.*` on every create and
 * change (AUD-01, PRC-24). Never deleted (TEN-06).
 *
 * @property string $id
 * @property ?string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $status
 */
abstract class ProcurementDocument extends ProcurementModel implements HasScope
{
    use Audited;

    protected string $auditModule = 'procurement';

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
