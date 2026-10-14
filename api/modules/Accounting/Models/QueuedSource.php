<?php

namespace Modules\Accounting\Models;

use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-26: a POS sale kept for its location's daily summary journal (the source as it would post alone). */
class QueuedSource extends Model implements HasScope
{
    use BelongsToTenant, CompanyRecord, HasUuids;

    protected $table = 'acc_source_queue';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'local_date' => 'immutable_date', 'summarised_at' => 'immutable_datetime'];
    }
}
