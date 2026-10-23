<?php

namespace App\Core\Marketplace;

use App\Core\Api\Sandbox\Sandbox;
use App\Core\Audit\Auditor;
use App\Core\Files\TenantFiles;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\Jobs\RollAppWebhookSecret;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\Models\MarketplaceDeveloper;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * MKT-01 (docs/adr/024-marketplace.md): a developer's apps.
 *
 * Creating an app makes its OAuth client (a client secret, shown once and
 * stored hashed by Passport), its webhook signing secret (shown once,
 * stored encrypted) and a first draft version. Drafts are edited, then
 * submitted for review (ADM-05); a submitted version can be withdrawn.
 * Reviewed versions never change: a change is a new version, copied from
 * the latest. Rows are written under the developer tenant's row-level
 * security: a tenant reaches only its own apps and can never approve.
 * Every change is audited in the developer tenant's chain
 * (`core.developer_app.*`), never with a secret.
 */
class DeveloperApps
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly AppSecrets $secrets,
    ) {}

    /**
     * @param  array<string, mixed>  $data  slug and the first version's content
     * @return array{app: MarketplaceApp, client_secret: string, webhook_secret: string}
     */
    public function create(User $actor, MarketplaceDeveloper $developer, array $data): array
    {
        $this->assertActive($developer);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $developer, $data) {
            $max = (int) config('marketplace.apps.max_per_developer', 20);

            if (MarketplaceApp::query()->where('developer_id', $developer->id)->whereNull('archived_at')->count() >= $max) {
                throw new ApiException(422, 'too_many_apps', __('marketplace.errors.too_many_apps', ['count' => $max]));
            }

            $clientSecret = Str::random(48);
            $webhookKey = AppSecrets::newWebhookKey();
            $clientId = (string) Str::uuid7();

            // The app first: row-level security admits a client only for an app of this developer.
            $app = new MarketplaceApp([
                'developer_id' => $developer->id,
                'developer_tenant_id' => $developer->developer_tenant_id,
                'slug' => $data['slug'],
                'client_id' => $clientId,
                'created_by' => $actor->id,
            ]);
            $app->id = (string) Str::uuid7();

            try {
                // Slugs are unique across developers, whose apps this tenant cannot see: the index decides.
                $app->save();
            } catch (UniqueConstraintViolationException) {
                $message = __('marketplace.errors.slug_taken');

                throw new ApiException(422, 'slug_taken', $message, ['slug' => [$message]]);
            }

            // Passport's client row holds no secret: only the marker of a confidential client.
            DB::connection(TenantContext::CONNECTION)->table('oauth_clients')->insert([
                'id' => $clientId,
                'name' => mb_substr($data['name'], 0, 80),
                'secret' => OAuthClient::CONFIDENTIAL,
                'redirect_uris' => '[]',
                'grant_types' => json_encode(OAuthClient::GRANTS),
                'revoked' => false,
                'app_id' => $app->id,
                'developer_tenant_id' => $developer->developer_tenant_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->secrets->store($app->id, $clientSecret, $webhookKey['raw']);

            $version = MarketplaceAppVersion::create([
                'app_id' => $app->id,
                'developer_tenant_id' => $developer->developer_tenant_id,
                'version' => 1,
                ...$this->content($data),
            ]);

            $this->auditor->record('core.developer_app.create', $app, null, ['slug' => $app->slug, 'client_id' => $clientId, 'version' => $version->version, 'name' => $version->name]);

            return ['app' => $app, 'client_secret' => $clientSecret, 'webhook_secret' => $webhookKey['display']];
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(MarketplaceAppVersion $version, array $data): MarketplaceAppVersion
    {
        $this->assertDraft($version);
        $before = $version->only(MarketplaceAppVersion::CONTENT);
        $version->fill($this->content($data));

        if ($version->isDirty()) {
            $changed = array_keys($version->getDirty());
            $version->save();
            $this->auditor->record('core.developer_app.version_update', $version, array_intersect_key($before, array_flip($changed)), $version->only($changed));
        }

        return $version;
    }

    public function storeLogo(MarketplaceAppVersion $version, UploadedFile $file): MarketplaceAppVersion
    {
        $this->assertDraft($version);
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw new ApiException(422, 'logo_type', __('marketplace.validation.logo_type'), ['logo' => [__('marketplace.validation.logo_type')]]),
        };

        // TEN-01: under the developer's tenant, like every tenant file (a draft always has one).
        $path = TenantFiles::key((string) $version->developer_tenant_id, 'marketplace', 'apps', $version->app_id, Str::uuid7().".{$extension}");
        Storage::disk(self::disk())->put($path, (string) file_get_contents($file->getRealPath()));
        $previous = $version->logo_path;
        $version->forceFill(['logo_path' => $path])->save();
        $this->auditor->record('core.developer_app.logo', $version, ['logo_path' => $previous], ['logo_path' => $path]);

        return $version;
    }

    /** MKT-01, ADM-05: a complete draft goes to the review queue. */
    public function submit(User $actor, MarketplaceAppVersion $version): MarketplaceAppVersion
    {
        $this->assertDraft($version);
        $app = MarketplaceApp::query()->findOrFail($version->app_id);

        if ($app->status === MarketplaceApp::SUSPENDED) {
            throw new ApiException(422, 'app_suspended', __('marketplace.errors.app_suspended'));
        }

        // Phase 8 review H2: only a live tenant's apps are listed; a sandbox closes when idle (API-05).
        if (app(Sandbox::class)->active()) {
            throw new ApiException(403, 'developer_sandbox', __('marketplace.errors.developer_sandbox'));
        }

        $errors = $this->incomplete($app, $version);

        if ($errors !== []) {
            throw new ApiException(422, 'version_incomplete', __('marketplace.errors.version_incomplete'), $errors);
        }

        $version->forceFill(['status' => MarketplaceAppVersion::SUBMITTED, 'submitted_at' => CarbonImmutable::now(), 'submitted_by' => $actor->id])->save();
        $this->auditor->record('core.developer_app.submit', $version, ['status' => MarketplaceAppVersion::DRAFT], ['status' => MarketplaceAppVersion::SUBMITTED, 'version' => $version->version, 'scopes' => $version->scopeList()]);

        return $version;
    }

    public function withdraw(MarketplaceAppVersion $version): MarketplaceAppVersion
    {
        if ($version->status !== MarketplaceAppVersion::SUBMITTED) {
            throw new ApiException(409, 'version_not_submitted', __('marketplace.errors.version_not_submitted'));
        }

        $version->forceFill(['status' => MarketplaceAppVersion::DRAFT])->save();
        $this->auditor->record('core.developer_app.withdraw', $version, ['status' => MarketplaceAppVersion::SUBMITTED], ['status' => MarketplaceAppVersion::DRAFT]);

        return $version;
    }

    /** A new draft copied from the latest version; one draft or submitted version at a time. */
    public function newVersion(MarketplaceApp $app): MarketplaceAppVersion
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($app) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['marketplace_versions:'.$app->id]);
            $latest = MarketplaceAppVersion::query()->where('app_id', $app->id)->orderByDesc('version')->firstOrFail();

            if (in_array($latest->status, [MarketplaceAppVersion::DRAFT, MarketplaceAppVersion::SUBMITTED], true)) {
                throw new ApiException(409, 'version_open', __('marketplace.errors.version_open'));
            }

            $version = MarketplaceAppVersion::create([
                'app_id' => $app->id,
                'developer_tenant_id' => $app->developer_tenant_id,
                'version' => $latest->version + 1,
                'logo_path' => $latest->logo_path,
                ...$latest->only(MarketplaceAppVersion::CONTENT),
            ]);
            $this->auditor->record('core.developer_app.version_create', $version, null, ['version' => $version->version, 'from' => $latest->version]);

            return $version;
        });
    }

    /** @return string the new client secret, shown once (the previous one stops at once) */
    public function rotateClientSecret(MarketplaceApp $app): string
    {
        $secret = Str::random(48);
        $this->secrets->store($app->id, $secret, null);
        $this->auditor->record('core.developer_app.rotate_client_secret', $app, null, ['client_id' => $app->client_id]);

        return $secret;
    }

    /**
     * A new webhook secret, shown once. Each tenant that installed the app
     * gets its install's new key (derived from it) by a job in its own
     * context (RollAppWebhookSecret); the previous key keeps signing for
     * `marketplace.apps.webhook_rotation_overlap_hours`, or stops at once
     * when $immediate (a secret that leaked).
     */
    public function rotateWebhookSecret(MarketplaceApp $app, bool $immediate = false): string
    {
        $key = AppSecrets::newWebhookKey();
        $until = $immediate ? null : CarbonImmutable::now()->addHours((int) config('marketplace.apps.webhook_rotation_overlap_hours', 24));

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($app, $key, $until) {
            $this->secrets->store($app->id, null, $key['raw']);
            $this->auditor->record('core.developer_app.rotate_webhook_secret', $app, null, ['previous_valid_until' => $until?->toIso8601String(), 'immediate' => $until === null]);
        });

        // As the app's developer tenant: the function lists installs only to it (and the platform role).
        foreach ($this->secrets->installedTenants($app->id, DB::connection(TenantContext::CONNECTION)) as $tenantId) {
            RollAppWebhookSecret::dispatch($tenantId, $app->id, $until?->toIso8601String())->afterCommit();
        }

        return $key['display'];
    }

    /** The developer withdraws the app from the listing (installs keep working), or puts it back. */
    public function archive(MarketplaceApp $app, bool $archived): MarketplaceApp
    {
        if ($app->status === MarketplaceApp::SUSPENDED) {
            throw new ApiException(422, 'app_suspended', __('marketplace.errors.app_suspended'));
        }

        $before = $app->status;
        $app->forceFill(['status' => $archived ? MarketplaceApp::ARCHIVED : MarketplaceApp::ACTIVE, 'archived_at' => $archived ? CarbonImmutable::now() : null])->save();
        $this->auditor->record($archived ? 'core.developer_app.archive' : 'core.developer_app.restore', $app, ['status' => $before], ['status' => $app->status]);

        return $app;
    }

    /**
     * What a submission still lacks (field => messages).
     *
     * @return array<string, list<string>>
     */
    public function incomplete(MarketplaceApp $app, MarketplaceAppVersion $version): array
    {
        $errors = [];

        if (trim((string) $version->description) === '' || mb_strlen((string) $version->description) < 40) {
            $errors['description'][] = __('marketplace.validation.description_short');
        }

        if ($version->scopeList() === []) {
            $errors['scopes'][] = __('marketplace.validation.scopes_required');
        }

        if (AppEndpoints::connectorKey($app) === null && $version->redirectList() === []) {
            $errors['redirect_uris'][] = __('marketplace.validation.redirect_required');
        }

        if ($version->eventList() !== [] && AppEndpoints::connectorKey($app) === null && ($version->webhook_url === null || $version->webhook_url === '')) {
            $errors['webhook_url'][] = __('marketplace.validation.webhook_url_required');
        }

        foreach (AppPricing::problems($version->pricing) as $field => $key) {
            $errors[$field][] = __($key);
        }

        if (($version->support_email ?? '') === '') {
            $errors['support_email'][] = __('marketplace.validation.support_email_required');
        }

        return $errors;
    }

    public static function disk(): string
    {
        return (string) (config('marketplace.apps.logo_disk') ?: TenantFiles::disk());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(array $data): array
    {
        $content = array_intersect_key($data, array_flip(MarketplaceAppVersion::CONTENT));

        foreach (['redirect_uris', 'scopes', 'webhook_events'] as $list) {
            if (array_key_exists($list, $content)) {
                $values = array_values(array_unique((array) $content[$list]));
                sort($values);
                $content[$list] = $values;
            }
        }

        if (array_key_exists('pricing', $content)) {
            $content['pricing'] = AppPricing::from((array) $content['pricing'])->toArray();
        }

        return $content;
    }

    private function assertDraft(MarketplaceAppVersion $version): void
    {
        if ($version->status !== MarketplaceAppVersion::DRAFT) {
            throw new ApiException(409, 'version_not_draft', __('marketplace.errors.version_not_draft'));
        }
    }

    private function assertActive(MarketplaceDeveloper $developer): void
    {
        if ($developer->status !== MarketplaceDeveloper::ACTIVE) {
            throw new ApiException(403, 'developer_suspended', __('marketplace.errors.developer_suspended'));
        }
    }
}
