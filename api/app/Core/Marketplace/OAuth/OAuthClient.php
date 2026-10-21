<?php

namespace App\Core\Marketplace\OAuth;

use App\Core\Marketplace\Models\MarketplaceApp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

/**
 * API-02 (OAuth), MKT-01: Passport's client, one per marketplace app.
 * Global (developer-owned): every tenant reads clients (the token
 * endpoint runs before a tenant is known), only the app's developer
 * writes its own, and review (platform role) sets the redirect URIs of
 * the approved version. The secret is shown once; its digest is kept
 * apart, in a table no runtime role reads (AppSecrets).
 * Only the authorization code (with PKCE) and refresh token grants.
 */
class OAuthClient extends Client
{
    public const GRANTS = ['authorization_code', 'refresh_token'];

    /** `secret` of a confidential client: the secret's digest is in marketplace_app_secrets, never on this row. */
    public const CONFIDENTIAL = 'external';

    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'app_id');
    }
}
