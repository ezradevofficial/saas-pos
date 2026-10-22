<?php

namespace App\Core\Marketplace;

use App\Core\Api\ApiKeyToken;
use App\Core\Api\ApiScopes;
use App\Core\Api\Models\ApiKey;
use App\Core\Audit\Auditor;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\Billing\AppBilling;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\OAuth\OAuthTokens;
use App\Core\Rbac\Grants;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\WebhookSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MKT-02 (docs/adr/024-marketplace.md): installing, re-consenting and
 * uninstalling apps in the current tenant.
 *
 * An install is an API key in all but name (ADR 020): a service principal
 * (status `service`) holding one role at one scope, granted with the same
 * anti-escalation check as an API key or a person's role (Grants: the
 * installing user must hold `core.role.assign` covering the scope and
 * every permission of the role there; never an Owner role), and an
 * `api_keys` row of kind `app` carrying the consented scopes. An app can
 * therefore never do more than the installing user could grant, nor more
 * than its scopes allow. Its webhook events are delivered through a
 * subscription owned by that key (`api_key` principal), to the version's
 * URL with `installation=<id>` added, signed with the app's secret.
 *
 * A newer approved version that asks for scopes or webhook events beyond
 * those consented changes nothing until the tenant consents again; until
 * then the app keeps what it was given (needsConsent).
 *
 * Every install, consent and uninstall is audited (`core.app.*`).
 */
class Installations
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Grants $grants,
        private readonly RoleManager $roles,
        private readonly ScopeResolver $resolver,
        private readonly Auditor $auditor,
        private readonly OAuthTokens $tokens,
        private readonly WebhookSubscriptions $webhooks,
        private readonly AppBilling $billing,
        private readonly Subscriptions $subscriptions,
        private readonly ApiScopes $scopes,
        private readonly AppSecrets $secrets,
    ) {}

    public const INSTALL = 'core.marketplace.install';

    /** The tenant's active install of $app, if any. */
    public function activeFor(MarketplaceApp $app): ?AppInstallation
    {
        return AppInstallation::query()->where('app_id', $app->id)->where('status', AppInstallation::ACTIVE)->first();
    }

    /** Whether the listed version asks for scopes or webhook events the install was not given. */
    public static function needsConsent(AppInstallation $installation, ?MarketplaceAppVersion $listed): bool
    {
        if ($listed === null || $listed->id === $installation->version_id) {
            return false;
        }

        return array_diff($listed->scopeList(), $installation->scopeList()) !== []
            || array_diff($listed->eventList(), $installation->eventList()) !== [];
    }

    /**
     * Install $app's listed version, or consent again to it, acting as
     * $role at $scope. Returns the active install.
     */
    public function consent(User $actor, MarketplaceApp $app, string $roleId, string $scopeType, ?string $scopeId): AppInstallation
    {
        $this->assertMayInstall($actor);
        $version = $app->listedVersion ?? throw self::unavailable();
        $existing = $this->activeFor($app);

        if ($existing === null && (! $app->isAvailable() || ! AppEndpoints::installable($app))) {
            throw self::unavailable();
        }

        if (! $app->serves()) {
            throw self::unavailable();
        }

        $role = $this->grants->role($roleId);
        $scope = $this->grants->scope($scopeType, $scopeId);

        if ($role->is_owner) {
            $message = __('api.errors.owner_role');

            throw new ApiException(422, 'app_owner_role', $message, ['role_id' => [$message]]);
        }

        // Same rule as API keys: the app never gets more than the installing user can grant.
        $this->grants->assertCanGrant($actor, $role, $scope);
        $price = $this->price($version);

        if ($price !== null) {
            $this->assertMayPay($actor);
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $app, $version, $existing, $role, $scope, $price) {
            // One install per app: concurrent consents are serialised per tenant and app.
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['app_installations:'.$this->tenants->require().':'.$app->id]);
            $existing = $existing === null ? $this->activeFor($app) : AppInstallation::query()->lockForUpdate()->find($existing->id);

            return $existing === null
                ? $this->install($actor, $app, $version, $role, $scope, $price)
                : $this->reconsent($actor, $existing, $app, $version, $role, $scope, $price);
        });
    }

    /** MKT-02: tokens revoked, principal's key revoked and role removed, webhooks switched off; audited. */
    public function uninstall(User $actor, AppInstallation $installation): AppInstallation
    {
        $this->assertMayInstall($actor);

        if (! $installation->isActive()) {
            throw new ApiException(409, 'app_not_installed', __('marketplace.errors.not_installed'));
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $installation) {
            $installation = AppInstallation::query()->lockForUpdate()->findOrFail($installation->id);
            $app = MarketplaceApp::query()->findOrFail($installation->app_id);
            $now = CarbonImmutable::now();

            $revoked = $this->tokens->revokeAll($app->client_id, $installation->principal_id);
            $key = ApiKey::query()->lockForUpdate()->findOrFail($installation->api_key_id);
            $key->forceFill(['revoked_at' => $now, 'revoked_by' => $actor->id])->save();
            // RBAC-12: through RoleManager (owner safety, audit).
            RoleAssignment::query()->where('user_id', $installation->principal_id)->get()->each(fn (RoleAssignment $a) => $this->roles->remove($a));
            $this->scopes->flush();

            if ($installation->webhook_subscription_id !== null) {
                WebhookSubscription::query()->whereKey($installation->webhook_subscription_id)->update([
                    'active' => false, 'disabled_reason' => 'uninstalled', 'disabled_at' => $now, 'archived_at' => $now, 'updated_by' => $actor->id, 'updated_at' => $now,
                ]);
                $this->webhooks->flush();
            }

            $installation->forceFill(['status' => AppInstallation::UNINSTALLED, 'uninstalled_by' => $actor->id, 'uninstalled_at' => $now])->save();
            $this->auditor->record('core.app.uninstall', $installation, ['status' => AppInstallation::ACTIVE], [
                'status' => AppInstallation::UNINSTALLED, 'app_id' => $app->id, 'tokens_revoked' => $revoked,
            ]);

            return $installation;
        });
    }

    private function install(User $actor, MarketplaceApp $app, MarketplaceAppVersion $version, Role $role, Scope $scope, ?array $price): AppInstallation
    {
        $tenant = Tenant::query()->findOrFail($this->tenants->require());
        $now = CarbonImmutable::now();
        $name = mb_substr($version->name, 0, 100);
        $principal = User::create([
            'name' => $name,
            'password' => Str::random(64),
            'locale' => $tenant->default_locale,
            'status' => User::STATUS_SERVICE,
        ]);

        // Its secret is never kept or shown: the key authenticates only through the app's OAuth tokens.
        $token = ApiKeyToken::generate($tenant->is_sandbox ? ApiKeyToken::MODE_TEST : ApiKeyToken::MODE_LIVE);
        $key = ApiKey::create([
            'name' => $name,
            'kind' => ApiKey::KIND_APP,
            'public_id' => $token->publicId,
            'mode' => $token->mode,
            'secret_hash' => $token->hash(),
            'principal_id' => $principal->id,
            'scopes' => $this->normalise($version->scopeList()),
            'created_by' => $actor->id,
        ]);

        $this->roles->createAssignment($principal, $role, $scope, $actor->id);

        $installation = AppInstallation::create([
            'app_id' => $app->id,
            'version_id' => $version->id,
            'api_key_id' => $key->id,
            'principal_id' => $principal->id,
            'scopes' => $this->normalise($version->scopeList()),
            'webhook_events' => $this->normalise($version->eventList()),
            'price' => $price,
            'installed_by' => $actor->id,
            'installed_at' => $now,
            'consented_by' => $actor->id,
            'consented_at' => $now,
        ]);

        $this->syncWebhooks($installation, $app, $version, $key, $scope, $actor);
        $this->auditor->record('core.app.install', $installation, null, [
            ...$this->described($installation),
            'role_id' => $role->id, 'scope_type' => $scope->type, 'scope_id' => $scope->id,
        ]);

        // MKT-03: a paid app is charged for the rest of the period now, then with every renewal.
        if ($price !== null) {
            $this->billing->chargeInstall($installation, $version);
        }

        return $installation;
    }

    private function reconsent(User $actor, AppInstallation $installation, MarketplaceApp $app, MarketplaceAppVersion $version, Role $role, Scope $scope, ?array $price): AppInstallation
    {
        $before = $this->described($installation);
        $key = ApiKey::query()->lockForUpdate()->findOrFail($installation->api_key_id);
        $principal = User::query()->findOrFail($installation->principal_id);
        $assignment = RoleAssignment::query()->where('user_id', $principal->id)->first();

        // A new role or scope: granted under the same rule, and the old one removed.
        if ($assignment === null || $assignment->role_id !== $role->id || $assignment->scope_type !== $scope->type || $assignment->scope_id !== $scope->id) {
            $this->roles->createAssignment($principal, $role, $scope, $actor->id);

            if ($assignment !== null) {
                // Moving the app needs the right to remove where it was, as for a person (security review).
                $this->grants->assertCanRevoke($actor, $assignment->load('role'));
                $this->roles->remove($assignment);
            }
        }

        $scopes = $this->normalise($version->scopeList());
        $key->forceFill(['scopes' => $scopes, 'name' => mb_substr($version->name, 0, 100)])->save();
        $principal->forceFill(['name' => mb_substr($version->name, 0, 100)])->save();
        $this->scopes->flush();

        $installation->forceFill([
            'version_id' => $version->id,
            'scopes' => $scopes,
            'webhook_events' => $this->normalise($version->eventList()),
            // A new price applies from the next renewal (ADR 024).
            'price' => $price,
            'consented_by' => $actor->id,
            'consented_at' => CarbonImmutable::now(),
        ])->save();

        $this->syncWebhooks($installation, $app, $version, $key, $scope, $actor);
        $after = $this->described($installation);
        $this->auditor->record('core.app.consent', $installation, $before, [...$after, 'role_id' => $role->id, 'scope_type' => $scope->type, 'scope_id' => $scope->id]);

        return $installation;
    }

    /**
     * The install's subscription follows the consented events: created on
     * the first event, updated on a new consent, switched off when none is
     * left. It belongs to the install's key (principal `api_key`), and is
     * filtered to the branch or company the app's role is held at.
     */
    private function syncWebhooks(AppInstallation $installation, MarketplaceApp $app, MarketplaceAppVersion $version, ApiKey $key, Scope $scope, User $actor): void
    {
        $url = AppEndpoints::webhookUrl($app, $version);
        $events = $installation->eventList();
        [$companyId, $branchId] = $this->filterOf($scope);
        $subscription = $installation->webhook_subscription_id === null ? null : WebhookSubscription::query()->find($installation->webhook_subscription_id);

        if ($url === null || $events === []) {
            if ($subscription !== null && $subscription->active) {
                $subscription->forceFill(['active' => false, 'events' => $events === [] ? $subscription->events : $events, 'updated_by' => $actor->id])->save();
                $this->webhooks->flush();
            }

            return;
        }

        $target = $url.(str_contains($url, '?') ? '&' : '?').'installation='.$installation->id;
        $values = ['url' => $target, 'events' => $events, 'company_id' => $companyId, 'branch_id' => $branchId, 'description' => mb_substr($version->name, 0, 200)];

        if ($subscription === null) {
            // Signed with the install's own key, derived in the database from the app's (ADR 024).
            $secret = $this->secrets->installKey($installation->id) ?? throw self::unavailable();
            $subscription = $this->webhooks->createForKey($key, $values, $actor, $secret);
            $installation->forceFill(['webhook_subscription_id' => $subscription->id])->save();

            return;
        }

        $this->webhooks->update($subscription, [...$values, 'active' => true], $actor, system: true);
    }

    /** @return array{0: ?string, 1: ?string} the company or branch filter matching the app's scope */
    private function filterOf(Scope $scope): array
    {
        return match ($scope->type) {
            Scope::COMPANY => [$scope->id, null],
            Scope::BRANCH => [null, $scope->id],
            Scope::LOCATION => [null, Location::query()->whereKey($scope->id)->value('branch_id')],
            default => [null, null],
        };
    }

    /** @return array{currency: string, monthly_minor: int, yearly_minor: int}|null what this tenant pays for $version (null: free) */
    public function price(MarketplaceAppVersion $version): ?array
    {
        $pricing = $version->pricing();

        if ($pricing->isFree()) {
            return null;
        }

        // A developer sandbox is never billed (API-05): its installs are free.
        if (Tenant::query()->whereKey($this->tenants->require())->value('is_sandbox')) {
            return null;
        }

        $currency = $this->subscriptions->current()?->currency;
        $snapshot = $currency === null ? null : $pricing->snapshot($currency);

        if ($snapshot === null) {
            throw new ApiException(422, 'app_price_unavailable', __('marketplace.errors.price_unavailable'));
        }

        return $snapshot;
    }

    private function assertMayInstall(User $actor): void
    {
        if (! $this->resolver->can($actor, self::INSTALL, Scope::tenant())) {
            throw new ApiException(403, 'forbidden', __('marketplace.errors.cannot_install'));
        }
    }

    /** A paid app adds to the tenant's invoice: its installer must be allowed to manage the subscription. */
    private function assertMayPay(User $actor): void
    {
        if (! $this->resolver->can($actor, 'core.subscription.manage', Scope::tenant())) {
            throw new ApiException(403, 'app_payment_not_allowed', __('marketplace.errors.cannot_pay'));
        }
    }

    public static function unavailable(): ApiException
    {
        return new ApiException(422, 'app_unavailable', __('marketplace.errors.unavailable'));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalise(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /** @return array<string, mixed> */
    private function described(AppInstallation $installation): array
    {
        return [
            'app_id' => $installation->app_id,
            'version_id' => $installation->version_id,
            'scopes' => $installation->scopeList(),
            'webhook_events' => $installation->eventList(),
            'price' => $installation->price,
        ];
    }
}
