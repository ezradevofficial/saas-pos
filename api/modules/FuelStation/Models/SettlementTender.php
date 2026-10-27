<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-12: declared, recorded and collected money of one tender kind in a settlement. */
class SettlementTender extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['cash', 'mobile_money', 'card', 'credit', 'voucher'];

    protected $table = 'fuel_settlement_tenders';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'declared_minor' => 'integer',
            'recorded_minor' => 'integer',
            'collected_minor' => 'integer',
        ];
    }
}
