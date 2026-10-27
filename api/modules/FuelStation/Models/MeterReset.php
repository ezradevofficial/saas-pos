<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-17: a request to re-baseline a nozzle meter, decided by another person. */
class MeterReset extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'meter_reset';

    protected $table = 'fuel_meter_resets';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'actor_verified' => 'boolean',
        ];
    }
}
