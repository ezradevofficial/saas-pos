<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FUEL-07: a pump at a station. */
class Pump extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'pump';

    protected $table = 'fuel_pumps';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    public function nozzles(): HasMany
    {
        return $this->hasMany(Nozzle::class, 'pump_id');
    }
}
