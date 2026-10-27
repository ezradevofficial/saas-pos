<?php

namespace Modules\Restaurant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-08: an item category routed to a station at a location. */
class StationRoute extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_station_routes';

    protected $guarded = [];
}
