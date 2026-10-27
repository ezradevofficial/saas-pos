<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FUEL-09, FUEL-11: a nozzle on a shift, with its attendant, readings and the figures the server computed. */
class ShiftNozzle extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fuel_shift_nozzles';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'price_minor' => 'integer',
            'recorded_minor' => 'integer',
            'expected_minor' => 'integer',
            'flags' => 'array',
        ];
    }

    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class, 'nozzle_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}
