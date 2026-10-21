<?php

namespace App\Core\Marketplace\Webhooks;

use App\Core\Api\Models\ApiKey;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Webhooks\Principals\WebhookPrincipal;
use App\Core\Webhooks\WebhookSubscriptions;

/**
 * HOOK-01, MKT-02 (ADR 021, ADR 024): a subscription owned by an API key,
 * the `api_key` principal type the webhooks ADR left to the public API.
 * Today only marketplace installs own one. It receives what the key's
 * principal may see: its role at its scope, limited by its scopes
 * (ScopeResolver), without the fields its rules hide. It stops receiving
 * when the key is revoked, the install is uninstalled or the app is
 * suspended.
 */
final class ApiKeyPrincipal implements WebhookPrincipal
{
    /** @var array<string, list<string>> */
    private array $hidden = [];

    private ?bool $active = null;

    public function __construct(
        public readonly ApiKey $key,
        public readonly ?User $user,
    ) {}

    public static function resolve(string $id): ?self
    {
        $key = ApiKey::query()->find($id);

        return $key === null ? null : new self($key, User::query()->find($key->principal_id));
    }

    public function type(): string
    {
        return WebhookSubscriptions::KEY_PRINCIPAL;
    }

    public function id(): string
    {
        return $this->key->id;
    }

    public function name(): string
    {
        return (string) $this->key->name;
    }

    public function isActive(): bool
    {
        return $this->active ??= $this->user?->isServicePrincipal() === true && $this->key->isUsable() && $this->serves();
    }

    public function can(string $permission, Scope $scope): bool
    {
        return $this->isActive() && app(ScopeResolver::class)->can($this->user, $permission, $scope);
    }

    public function hiddenFields(string $resource): array
    {
        return $this->user === null ? [] : ($this->hidden[$resource] ??= app(FieldRules::class)->for($this->user, $resource)['hidden']);
    }

    public function actingUser(): ?User
    {
        return $this->isActive() ? $this->user : null;
    }

    public function notifiableUserId(): ?string
    {
        return null;
    }

    /** A key of kind `app` serves while its install is active and its app not suspended. */
    private function serves(): bool
    {
        if ($this->key->kind !== ApiKey::KIND_APP) {
            return true;
        }

        $installation = AppInstallation::query()->where('api_key_id', $this->key->id)->where('status', AppInstallation::ACTIVE)->first();
        $app = $installation === null ? null : MarketplaceApp::query()->find($installation->app_id);

        return $app !== null && $app->serves();
    }
}
