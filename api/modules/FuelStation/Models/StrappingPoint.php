<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-06: one (dip in cm, litres) point of a chart. */
class StrappingPoint extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $table = 'fuel_strapping_points';

    protected $guarded = ['id', 'tenant_id'];
}
