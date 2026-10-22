<?php

namespace App\Core\Marketplace\Models;

use App\Core\Api\Models\ApiKey;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Webhooks\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MKT-02: an app installed in a tenant. Like an API key (ADR 020), it
 * acts through a service principal (`principal_id`, a user with status
 * `service`) holding one scoped role assignment, and an `api_keys` row of
 * kind `app` that carries the consented scopes, so row-level security,
 * policies, scope and field rules, rate limits and usage apply unchanged.
 * Uninstalled, never deleted (TEN-06).
 */
class AppInstallation extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const UNINSTALLED = 'uninstalled';

    protected $table = 'app_installations';

    protected $fillable = ['app_id', 'version_id', 'api_key_id', 'principal_id', 'scopes', 'webhook_events', 'price', 'installed_by', 'installed_at', 'consented_by', 'consented_at'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'webhook_events' => 'array',
            'price' => 'array',
            'installed_at' => 'immutable_datetime',
            'consented_at' => 'immutable_datetime',
            'uninstalled_at' => 'immutable_datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'app_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAppVersion::class, 'version_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        return array_values(array_filter((array) $this->scopes, 'is_string'));
    }

    /** @return list<string> */
    public function eventList(): array
    {
        return array_values(array_filter((array) $this->webhook_events, 'is_string'));
    }
}
