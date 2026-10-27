<?php

namespace Modules\VanSales\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-20: money in or out of a run by kind and currency. */
class Tender extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_tenders';

    protected $guarded = [];
}
