<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ACC-09: a customer or supplier invoice or credit note. Drafts change;
 * a posted document never does, except its open balance, which
 * allocations reduce (ACC-10).
 */
class Document extends Model implements HasScope
{
    use Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const KINDS = ['customer_invoice', 'customer_credit', 'supplier_invoice', 'supplier_credit'];

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::POSTED, self::CANCELLED];

    protected $table = 'acc_documents';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'document';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'tax_inclusive' => 'boolean',
            'fx' => FxSnapshot::class.':fx',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'base_subtotal_minor' => 'integer',
            'base_tax_minor' => 'integer',
            'base_total_minor' => 'integer',
            'open_minor' => 'integer',
        ];
    }

    /** customer (receivable) or supplier (payable). */
    public function side(): string
    {
        return str_starts_with($this->kind, 'customer') ? 'customer' : 'supplier';
    }

    public function isCredit(): bool
    {
        return str_ends_with($this->kind, '_credit');
    }

    public function money(string $field): Money
    {
        return Money::ofMinor((string) $this->getAttribute($field), $this->currency);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function credits(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credits_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
