<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-20: a fleet vehicle of a customer. */
class Vehicle extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const BLOCKED = 'blocked';

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'vehicle';

    protected $table = 'fuel_vehicles';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];
}
