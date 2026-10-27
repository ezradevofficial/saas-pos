<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FUEL-06: an immutable calibration chart of a tank, effective from a time. */
class StrappingChart extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'strapping_chart';

    protected $table = 'fuel_strapping_charts';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
        ];
    }

    public function points(): HasMany
    {
        return $this->hasMany(StrappingPoint::class, 'chart_id')->orderBy('dip_cm');
    }
}
