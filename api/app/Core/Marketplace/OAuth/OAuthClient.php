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
 * the approved version. The secret is hashed by Passport and shown once.
 * Only the authorization code (with PKCE) and refresh token grants.
 */
class OAuthClient extends Client
{
    public const GRANTS = ['authorization_code', 'refresh_token'];

    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'app_id');
    }
}
