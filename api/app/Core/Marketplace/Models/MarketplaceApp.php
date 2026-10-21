<?php

namespace App\Core\Marketplace\Models;

use App\Core\Marketplace\AppSecrets;
use App\Core\Marketplace\OAuth\OAuthClient;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MKT-01, ADM-05: a marketplace app and its OAuth client (one per app);
 * its secrets are in `marketplace_app_secrets`, which no runtime role
 * reads (AppSecrets). Listed through `listed_version_id`, the latest version
 * staff approved. Suspended by staff (tokens stop at once, every install
 * is told); archived by its developer (no new installs).
 */
class MarketplaceApp extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const ARCHIVED = 'archived';

    protected $table = 'marketplace_apps';

    protected $fillable = ['developer_id', 'developer_tenant_id', 'slug', 'client_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceDeveloper::class, 'developer_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MarketplaceAppVersion::class, 'app_id');
    }

    public function listedVersion(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAppVersion::class, 'listed_version_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id');
    }

    /** Listed, and neither suspended nor archived: tenants may install it. */
    public function isAvailable(): bool
    {
        return $this->status === self::ACTIVE && $this->listed_version_id !== null;
    }

    /**
     * Its tokens and webhooks work (an archived app keeps serving the tenants
     * that installed it); a suspended developer, or a closed developer
     * tenant, stops all its apps as a suspension does.
     */
    public function serves(): bool
    {
        return $this->status !== self::SUSPENDED && $this->listed_version_id !== null
            && app(AppSecrets::class)->developerActive($this->developer_id);
    }

    public function isFirstParty(): bool
    {
        return $this->developer_tenant_id === null;
    }
}
