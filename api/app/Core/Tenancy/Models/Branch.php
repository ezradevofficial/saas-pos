<?php

namespace App\Core\Tenancy\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A branch of a company (TEN-04). */
class Branch extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'company_id', 'name', 'code', 'timezone', 'address'];

    protected function casts(): array
    {
        return ['address' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** RBAC-04: permission checks on this record apply at its scope. */
    public function scope(): Scope
    {
        return Scope::branch($this->id);
    }
}
