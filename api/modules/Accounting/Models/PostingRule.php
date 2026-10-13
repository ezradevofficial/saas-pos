<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ACC-07: how one source event type posts in a company: lines of
 * `{field, side, account_id, party}`. A new rule for the same event type
 * archives the one before (one active per company and type).
 */
class PostingRule extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, CompanyRecord, HasUuids;

    protected $table = 'acc_posting_rules';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'posting_rule';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['lines' => 'array'];
    }
}
