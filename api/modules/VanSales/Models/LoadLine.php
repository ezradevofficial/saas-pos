<?php

namespace Modules\VanSales\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-07: a line of a load sheet, in the item's base unit. */
class LoadLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_load_lines';

    protected $guarded = [];
}
