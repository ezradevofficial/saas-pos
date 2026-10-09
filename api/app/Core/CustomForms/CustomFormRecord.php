<?php

namespace App\Core\CustomForms;

use App\Core\Audit\Audited;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CF-04: one filled-in custom form at a company (and branch and location).
 *
 * Status: draft (being written) → pending (its flow runs) → approved or
 * rejected; without a workflow, draft → submitted. A draft or pending
 * record may be cancelled. Never deleted (TEN-06); audited as
 * `core.custom_form.*`.
 *
 * @property string $id
 * @property string $type_id
 * @property ?string $number
 * @property string $company_id
 * @property ?string $branch_id
 * @property ?string $location_id
 * @property string $status
 * @property array $custom
 * @property array $totals
 * @property string $created_by
 */
class CustomFormRecord extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditResource = 'custom_form';

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::PENDING, self::SUBMITTED, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $fillable = ['type_id', 'number', 'company_id', 'branch_id', 'location_id', 'status', 'totals', 'amount_minor', 'amount_currency', 'created_by', 'submitted_at', 'decided_at'];

    protected $attributes = ['custom' => '{}', 'totals' => '{}', 'status' => self::DRAFT];

    protected function casts(): array
    {
        return ['custom' => 'array', 'totals' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CustomFormType::class, 'type_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomFormLine::class, 'record_id')->orderBy('position');
    }

    public function attachmentFiles(): HasMany
    {
        return $this->hasMany(CustomFormAttachment::class, 'record_id')->orderBy('created_at');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** RBAC-04: where the record belongs, narrowest first. */
    public function scope(): Scope
    {
        return $this->documentScope()->scope();
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope($this->company_id, $this->branch_id, $this->location_id);
    }

    public function amount(): ?Money
    {
        return $this->amount_minor === null ? null : Money::ofMinor((string) $this->amount_minor, (string) $this->amount_currency);
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT && ! $this->isArchived();
    }
}
