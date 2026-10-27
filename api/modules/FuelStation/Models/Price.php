<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-08: an immutable, effective-dated price per litre of a product at a station. */
class Price extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'price';

    protected $table = 'fuel_prices';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'effective_from' => 'immutable_datetime',
        ];
    }
}
