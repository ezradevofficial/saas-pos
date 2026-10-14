<?php

namespace Modules\HR\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-03, HR-10: one identifier of an employee (a type from the country
 * pack). The value is encrypted at rest; `value_hint` is its last 4
 * characters and `value_digest` a keyed digest for uniqueness. Changes are
 * audited on the employee by type only (HR-11), not through model events.
 *
 * @property string $type
 * @property string $value
 * @property string $value_hint
 */
class EmployeeIdentifier extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hr_employee_identifiers';

    protected $guarded = [];

    protected $hidden = ['value', 'value_digest'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
