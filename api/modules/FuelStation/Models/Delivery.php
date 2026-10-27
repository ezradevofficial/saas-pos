<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FUEL-25: fuel received into a tank against a delivery note, measured by dips before and after. */
class Delivery extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'delivery';

    protected $table = 'fuel_deliveries';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'unit_cost_minor' => 'integer',
            'cost_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'flags' => 'array',
        ];
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class, 'tank_id');
    }
}
