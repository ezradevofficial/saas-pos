<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-23: a dip of a tank, converted to litres by the chart in force. */
class Dip extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['routine', 'shift_open', 'shift_close', 'delivery_before', 'delivery_after'];

    /** The kinds that reconcile the tank (FUEL-24). */
    public const RECONCILING = ['routine', 'shift_open', 'shift_close'];

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'dip';

    protected $table = 'fuel_dips';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'taken_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
