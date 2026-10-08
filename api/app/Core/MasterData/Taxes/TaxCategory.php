<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tax category items point to (MD-03), with a default tax code per
 * company (`tax_category_codes`). Shared across the group when company_id
 * is null, else one company's, following the items sharing mode (TEN-08,
 * TaxCategorySharedRecords). Audited as `core.tax_category.*`.
 */
class TaxCategory extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['company_id', 'name'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(TaxCategoryCode::class);
    }

    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    /** RBAC-04: a shared category is managed at tenant scope, a company's at that company. */
    public function scope(): Scope
    {
        return $this->company_id === null ? Scope::tenant() : Scope::company($this->company_id);
    }
}
