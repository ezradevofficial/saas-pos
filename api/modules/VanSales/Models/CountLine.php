<?php

namespace Modules\VanSales\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-19: a stock line of a count. */
class CountLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_count_lines';

    protected $guarded = [];
}
