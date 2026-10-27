<?php

namespace Modules\VanSales\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-20: a cash or mobile money line of a count. */
class CountTender extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_count_tenders';

    protected $guarded = [];
}
