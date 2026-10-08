<?php

namespace Modules\POS\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** POS-04: cash paid into or out of the drawer during a shift. */
class CashMovement extends Model
{
    use BelongsToTenant, HasUuids;

    public const PAY_IN = 'pay_in';

    public const PAY_OUT = 'pay_out';

    public const KINDS = [self::PAY_IN, self::PAY_OUT];

    protected $table = 'pos_cash_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
