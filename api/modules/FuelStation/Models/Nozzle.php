<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FUEL-07, FUEL-16: a nozzle of a pump, linked to a tank, with its last accepted meter reading. */
class Nozzle extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'nozzle';

    protected $table = 'fuel_nozzles';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    protected function casts(): array
    {
        return [
            'last_reading_at' => 'immutable_datetime',
        ];
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(Pump::class, 'pump_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class, 'tank_id');
    }
}
