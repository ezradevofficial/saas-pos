<?php

namespace App\Core\Tenancy\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A legal entity of a tenant (TEN-03). */
class Company extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'legal_name', 'tax_id', 'country', 'base_currency',
        'fiscal_year_start_month', 'address', 'timezone',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
