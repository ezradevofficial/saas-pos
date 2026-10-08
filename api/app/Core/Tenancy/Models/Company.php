<?php

namespace App\Core\Tenancy\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Policies\CompanyPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A legal entity of a tenant (TEN-03). */
#[UsePolicy(CompanyPolicy::class)]
class Company extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'legal_name', 'tax_id', 'country', 'base_currency',
        'fiscal_year_start_month', 'address', 'timezone',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'fiscal_year_start_month' => 'integer',
            // CUR-02: set once by BaseCurrencyLock on the first posting.
            'base_currency_locked_at' => 'datetime',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** RBAC-04: permission checks on this record apply at its scope. */
    public function scope(): Scope
    {
        return Scope::company($this->id);
    }
}
