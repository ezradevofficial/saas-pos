<?php

namespace Modules\Procurement\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What every Procurement table shares (docs/modules/procurement.md): a
 * tenant row under row-level security (TEN-01) with a UUID v7 key.
 */
abstract class ProcurementModel extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];
}
