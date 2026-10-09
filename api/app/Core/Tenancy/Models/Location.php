<?php

namespace App\Core\Tenancy\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Policies\LocationPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An outlet, warehouse, store or office of a branch (TEN-05). */
#[UsePolicy(LocationPolicy::class)]
class Location extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'branch_id', 'name', 'type', 'code'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** RBAC-04: permission checks on this record apply at its scope. */
    public function scope(): Scope
    {
        return Scope::location($this->id);
    }
}
