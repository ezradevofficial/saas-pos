<?php

namespace Modules\VanSales\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-19: quantities sold or taken back, per source. */
class StockLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_stock_lines';

    protected $guarded = [];
}
