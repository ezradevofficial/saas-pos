<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-06: a template of journal lines made into a journal on each due date. */
class RecurringJournal extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const FREQUENCIES = ['monthly', 'quarterly', 'yearly'];

    public const STATUSES = ['active', 'paused', 'ended'];

    protected $table = 'acc_recurring_journals';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'recurring_journal';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['next_run_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'last_run_on' => 'immutable_date', 'lines' => 'array'];
    }
}
