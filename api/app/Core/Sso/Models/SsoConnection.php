<?php

namespace App\Core\Sso\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-11, ADR 023: one identity provider of the tenant. `settings` holds
 * the public values (client id, Entra directory id, Google hosted domain,
 * SAML IdP entity id, sign-in URL and certificates); `client_secret` is
 * encrypted and never returned. Removing a connection archives it (TEN-06).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $name
 * @property array<string, mixed> $settings
 * @property ?string $client_secret
 * @property bool $enabled
 */
class SsoConnection extends Model
{
    use BelongsToTenant, HasUuids;

    public const ENTRA = 'entra';

    public const GOOGLE = 'google';

    public const SAML = 'saml';

    public const PROVIDERS = [self::ENTRA, self::GOOGLE, self::SAML];

    protected $fillable = ['provider', 'name', 'settings', 'client_secret', 'enabled', 'tested_at', 'last_test', 'created_by', 'archived_at'];

    protected $hidden = ['client_secret'];

    protected $attributes = [
        'settings' => '{}',
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'client_secret' => 'encrypted',
            'enabled' => 'boolean',
            'tested_at' => 'immutable_datetime',
            'last_test' => 'array',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function isOidc(): bool
    {
        return $this->provider !== self::SAML;
    }

    /** Usable for sign-in: enabled and not archived. */
    public function isLive(): bool
    {
        return $this->enabled && $this->archived_at === null;
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? null;
    }

    /** SAML: this connection's SP entity id (the audience the IdP must name). */
    public function spEntityId(): string
    {
        return self::callbackBase().'/api/v1/auth/sso/saml/'.$this->id;
    }

    /** SAML: where the IdP posts its response (Recipient, Destination). */
    public function acsUrl(): string
    {
        return $this->spEntityId().'/acs';
    }

    /** OIDC: the one redirect URI every OIDC connection registers at its IdP. */
    public static function oidcRedirectUri(): string
    {
        return self::callbackBase().'/api/v1/auth/sso/oidc/callback';
    }

    private static function callbackBase(): string
    {
        return rtrim((string) config('sso.callback_base'), '/');
    }
}
