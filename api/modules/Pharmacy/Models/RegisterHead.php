<?php

namespace Modules\Pharmacy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PHA-18: the last entry number and balance of the controlled-drug register
 * of one item at one location; locked while an entry is appended.
 *
 * @property string $company_id
 * @property string $location_id
 * @property string $item_id
 * @property int $last_no
 * @property string $balance
 */
class RegisterHead extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pharmacy_register_heads';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['last_no' => 'integer'];
    }
}
