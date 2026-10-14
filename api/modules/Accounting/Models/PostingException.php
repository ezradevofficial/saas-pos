<?php

namespace Modules\Accounting\Models;

use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-07: a source that could not post yet (no rule, closed period, missing rate...), kept to retry. */
class PostingException extends Model implements HasScope
{
    use BelongsToTenant, CompanyRecord, HasUuids;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $table = 'acc_posting_exceptions';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_attempt_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
