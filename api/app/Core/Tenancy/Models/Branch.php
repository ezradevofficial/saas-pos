<?php

namespace App\Core\Tenancy\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A branch of a company (TEN-04). */
class Branch extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

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
}
