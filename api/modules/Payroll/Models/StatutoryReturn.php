<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PAY-21: a statutory return generated for a company and period. Only the
 * record is kept; its file is rebuilt on demand from the posted lines and
 * HR's identifiers (never stored in clear).
 *
 * @property string $company_id
 * @property string $type
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property array $run_ids
 * @property array $totals
 * @property int $employee_count
 * @property bool $needs_confirmation
 */
class StatutoryReturn extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'payroll_returns';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'return';

    protected array $auditHidden = ['totals'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'run_ids' => 'array', 'totals' => 'array', 'needs_confirmation' => 'boolean', 'employee_count' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
