<?php

namespace Modules\POS\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** POS-04: one currency of a shift's cash: opening float, counted, expected and variance (minor units). */
class ShiftBalance extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pos_shift_balances';

    protected $guarded = [];
}
