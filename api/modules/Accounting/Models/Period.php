<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-08: a month of a company's fiscal year; closed periods take no posting (database trigger). */
class Period extends Model implements HasScope
{
    use Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::CLOSED];

    protected $table = 'acc_periods';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'period';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'fiscal_year' => 'integer',
            'number' => 'integer',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
