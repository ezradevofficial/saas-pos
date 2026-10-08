<?php

namespace Modules\POS\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** POS-05: a quantity of one sale line given back. */
class RefundLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pos_refund_lines';

    protected $guarded = [];
}
