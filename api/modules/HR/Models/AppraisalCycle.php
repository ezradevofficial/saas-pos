<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** HR-30: an appraisal cycle of a company. */
class AppraisalCycle extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const STATUSES = ['draft', 'open', 'closed'];

    protected $table = 'hr_appraisal_cycles';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'appraisal_cycle';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'immutable_date', 'period_end' => 'immutable_date'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
