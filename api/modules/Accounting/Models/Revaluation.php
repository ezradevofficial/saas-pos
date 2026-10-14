<?php

namespace Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-15: a period-end revaluation: the rates used, each item revalued and the journals. */
class Revaluation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_revaluations';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['as_of' => 'immutable_date', 'rates' => 'array', 'items' => 'array'];
    }
}
