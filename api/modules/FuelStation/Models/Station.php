<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-02: a location marked as a forecourt, with the currency of its prices and shifts. */
class Station extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'station';

    protected $table = 'fuel_stations';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];
}
