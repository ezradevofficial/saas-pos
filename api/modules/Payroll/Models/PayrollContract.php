<?php

namespace Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-05: Payroll's copy of an HR contract (ContractChanged and HR's
 * `EmploymentContracts` contract). Not audited: HR audits the contract;
 * the copy only follows it.
 *
 * @property string $contract_id
 * @property string $employee_id
 * @property string $company_id
 * @property CarbonImmutable $start_date
 * @property ?CarbonImmutable $end_date
 * @property string $pay_basis
 * @property int $pay_minor
 * @property string $currency
 * @property ?string $hours_per_week
 * @property string $status
 */
class PayrollContract extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'payroll_contracts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'pay_minor' => 'integer'];
    }
}
