<?php

namespace App\Core\Numbering;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How one document type is numbered (NUM-01) for the whole tenant
 * (company_id null), a company, or a branch; the most specific applies.
 * Audited as `core.number_format.*`. Configuration, kept while it has
 * issued numbers (its counters reference it).
 */
class NumberFormat extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const RESET_NEVER = 'never';

    public const RESET_YEARLY = 'yearly';

    public const RESETS = [self::RESET_NEVER, self::RESET_YEARLY];

    protected $fillable = ['document_type', 'company_id', 'branch_id', 'pattern', 'reset', 'gapless'];

    protected $attributes = ['gapless' => false, 'reset' => self::RESET_NEVER];

    protected function casts(): array
    {
        return ['gapless' => 'boolean'];
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(NumberSequence::class);
    }

    public function parsed(): Pattern
    {
        return Pattern::parse($this->pattern);
    }

    /** RBAC-04: a tenant format is managed at tenant scope, others at their company or branch. */
    public function scope(): Scope
    {
        return match (true) {
            $this->branch_id !== null => Scope::branch($this->branch_id),
            $this->company_id !== null => Scope::company($this->company_id),
            default => Scope::tenant(),
        };
    }
}
