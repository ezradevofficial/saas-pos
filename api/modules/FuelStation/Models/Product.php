<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-04: a core stock item sold as fuel. */
class Product extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const TYPES = ['petrol', 'diesel', 'kerosene', 'lpg', 'other'];

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'product';

    protected $table = 'fuel_products';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];
}
