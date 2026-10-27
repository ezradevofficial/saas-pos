<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-21: a prepaid fuel voucher with a balance. */
class Voucher extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const VOID = 'void';

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'voucher';

    protected $table = 'fuel_vouchers';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'value_minor' => 'integer',
            'balance_minor' => 'integer',
            'expires_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
