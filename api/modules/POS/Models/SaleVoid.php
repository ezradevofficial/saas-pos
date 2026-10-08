<?php

namespace Modules\POS\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** POS-05: a whole sale cancelled, by whom, and who allowed it (AUTH-08). */
class SaleVoid extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pos_sale_voids';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['override_verified' => 'boolean', 'voided_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
