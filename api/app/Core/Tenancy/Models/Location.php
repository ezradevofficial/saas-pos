<?php

namespace App\Core\Tenancy\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An outlet, warehouse, store or office of a branch (TEN-05). */
class Location extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'branch_id', 'name', 'type'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
