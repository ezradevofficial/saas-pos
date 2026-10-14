<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-20: a company's overtime, night and grace rules from a date (rows
 * never overlap). HR classifies minutes with them; Payroll applies rates.
 */
class TimeRule extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $table = 'hr_time_rules';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'time_rule';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'daily_overtime_after_minutes' => 'integer',
            'weekly_overtime_after_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'early_leave_grace_minutes' => 'integer',
            'rest_days' => 'array',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
