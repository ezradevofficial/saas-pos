<?php

namespace App\Core\Marketplace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MKT-01: a developer account. Owned by the tenant that registered it
 * (`developer_tenant_id`), or the platform's own for first-party
 * connectors (MKT-04). Global (no tenant_id): row-level security lets a
 * tenant read and edit its own account, and read the developer of any
 * listed app; staff (platform role) read all and suspend.
 */
class MarketplaceDeveloper extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $table = 'marketplace_developers';

    protected $fillable = ['developer_tenant_id', 'name', 'email', 'website', 'terms_accepted_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'first_party' => 'boolean',
            'terms_accepted_at' => 'immutable_datetime',
        ];
    }

    public function apps(): HasMany
    {
        return $this->hasMany(MarketplaceApp::class, 'developer_id');
    }
}
