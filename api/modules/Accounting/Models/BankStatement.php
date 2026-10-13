<?php

namespace Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-13: one imported statement file. */
class BankStatement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_bank_statements';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }
}
