<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-23, SAL-24: what a staff member is paid from a batch. Amounts are not shown in the audit log of the batch.
 *
 * @property string $batch_id
 * @property string $staff_id
 * @property int $amount_minor
 * @property string $currency
 * @property int $entry_count
 * @property ?string $employee_id
 * @property string $status
 * @property ?string $payroll_ref
 */
class Payout extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_commission_payouts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'entry_count' => 'integer'];
    }
}
