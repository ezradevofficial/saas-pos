<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-21: a line of the commission ledger (append-only; a trigger refuses any change but setting `batch_id` once).
 * A reversal is a negative entry that names the entry it takes back.
 *
 * @property string $company_id
 * @property ?string $branch_id
 * @property string $staff_id
 * @property string $kind
 * @property string $line_kind
 * @property string $sale_id
 * @property ?string $sale_line_id
 * @property ?string $appointment_id
 * @property ?string $appointment_line_id
 * @property ?string $item_id
 * @property string $qty
 * @property int $base_minor
 * @property string $currency
 * @property int $amount_minor
 * @property ?string $reverses_id
 * @property ?string $batch_id
 */
class CommissionEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_commission_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'base_minor' => 'integer', 'rule_snapshot' => 'array', 'earned_at' => 'immutable_datetime'];
    }
}
