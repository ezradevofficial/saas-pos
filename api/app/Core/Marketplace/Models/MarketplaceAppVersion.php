<?php

namespace App\Core\Marketplace\Models;

use App\Core\Marketplace\AppPricing;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MKT-01, ADM-05: one version of an app, what a tenant consents to. A
 * draft is edited by its developer, submitted for review, then approved
 * or rejected by staff (a submitted version may be withdrawn to a draft).
 * A reviewed version never changes (trigger); a change is a new version,
 * and one that asks for more scopes or webhook events needs each tenant's
 * consent again (Installations::needsConsent).
 *
 * Texts are stored once, in the language the developer wrote them in
 * (names and texts are not per-language).
 */
class MarketplaceAppVersion extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CATEGORIES = ['accounting', 'analytics', 'attendance', 'commerce', 'delivery', 'messaging', 'payments', 'productivity', 'other'];

    /** What a developer edits on a draft. */
    public const CONTENT = ['name', 'tagline', 'description', 'category', 'website', 'support_email', 'privacy_url', 'redirect_uris', 'scopes', 'webhook_url', 'webhook_events', 'pricing'];

    protected $table = 'marketplace_app_versions';

    protected $fillable = ['app_id', 'developer_tenant_id', 'version', 'name', 'tagline', 'description', 'category', 'logo_path', 'website', 'support_email', 'privacy_url', 'redirect_uris', 'scopes', 'webhook_url', 'webhook_events', 'pricing'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'scopes' => 'array',
            'webhook_events' => 'array',
            'pricing' => 'array',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'app_id');
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

    /** @return list<string> */
    public function redirectList(): array
    {
        return array_values(array_filter((array) $this->redirect_uris, 'is_string'));
    }

    public function pricing(): AppPricing
    {
        return AppPricing::from((array) $this->pricing);
    }
}
