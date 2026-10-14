<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ACC-03: a journal. Drafts and pending journals change; a posted journal
 * never does (acc_journal_immutable), balances in base currency
 * (acc_journal_balanced) and lies in an open period (acc_journal_period_open).
 */
class Journal extends Model implements HasScope
{
    use Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::POSTED, self::REJECTED, self::CANCELLED];

    public const KINDS = ['manual', 'system', 'recurring', 'reversal', 'revaluation', 'closing', 'opening'];

    /** Journals a person drafts and submits (ACC-04, ACC-06). */
    public const EDITABLE_KINDS = ['manual', 'recurring'];

    protected $table = 'acc_journals';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'journal';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'total_base_minor' => 'integer',
            'source_link' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function isEditable(): bool
    {
        return in_array($this->kind, self::EDITABLE_KINDS, true) && in_array($this->status, [self::DRAFT, self::REJECTED], true);
    }
}
