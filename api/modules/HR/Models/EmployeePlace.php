<?php

namespace Modules\HR\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-23: where an employee worked from a date (their company, branch and
 * location), kept when they move so punches a till queued offline before
 * the move are still accepted. Written by Employees; changes are audited on
 * the employee.
 */
class EmployeePlace extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hr_employee_places';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
