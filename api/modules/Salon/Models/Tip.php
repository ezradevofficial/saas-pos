<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-22: a tip a staff member received with a sale (or the reversal of it by a void).
 *
 * @property string $company_id
 * @property ?string $branch_id
 * @property string $staff_id
 * @property string $sale_id
 * @property string $kind
 * @property string $currency
 * @property int $amount_minor
 */
class Tip extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_tips';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'earned_at' => 'immutable_datetime'];
    }
}
