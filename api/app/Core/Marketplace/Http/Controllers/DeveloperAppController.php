<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Marketplace\DeveloperApps;
use App\Core\Marketplace\Developers;
use App\Core\Marketplace\Http\MarketplacePresenter;
use App\Core\Marketplace\Http\Requests\DeveloperAppLogoRequest;
use App\Core\Marketplace\Http\Requests\DeveloperRequest;
use App\Core\Marketplace\Http\Requests\StoreDeveloperAppRequest;
use App\Core\Marketplace\Http\Requests\UpdateDraftRequest;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\Models\MarketplaceDeveloper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * MKT-01: a developer's apps (developer portal). `developer_app` binds
 * only this tenant's own apps (row-level security and the developer
 * tenant id). Secrets are in the create and rotate answers only, never
 * cached.
 */
class DeveloperAppController
{
    public function __construct(
        private readonly Developers $developers,
        private readonly DeveloperApps $apps,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(DeveloperRequest $request): JsonResponse
    {
        $developer = $this->developers->current();
        $apps = $developer === null ? collect() : MarketplaceApp::query()->where('developer_id', $developer->id)->with('versions')->orderBy('created_at')->get();

        return new JsonResponse(['data' => $apps->map(fn (MarketplaceApp $app) => $this->presenter->developerApp($app))->all()]);
    }

    public function store(StoreDeveloperAppRequest $request): JsonResponse
    {
        ['app' => $app, 'client_secret' => $clientSecret, 'webhook_secret' => $webhookSecret] = $this->apps->create($request->user(), $this->developer(), $request->validated());

        return $this->withSecrets($app, ['client_secret' => $clientSecret, 'webhook_secret' => $webhookSecret], 201);
    }

    public function show(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->developerApp($developerApp->load('versions'))]);
    }

    public function updateDraft(UpdateDraftRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $this->apps->updateDraft($this->draft($developerApp), $request->validated());

        return $this->show($request, $developerApp);
    }

    public function storeLogo(DeveloperAppLogoRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $this->apps->storeLogo($this->draft($developerApp), $request->file('logo'));

        return $this->show($request, $developerApp);
    }

    /** The newest version's logo (a draft's is not public). */
    public function logo(DeveloperRequest $request, MarketplaceApp $developerApp): Response
    {
        $version = MarketplaceAppVersion::query()->where('app_id', $developerApp->id)->whereNotNull('logo_path')->orderByDesc('version')->first();
        abort_if($version === null || ! Storage::disk(DeveloperApps::disk())->exists($version->logo_path), 404);

        return Storage::disk(DeveloperApps::disk())->response($version->logo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function submit(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $this->apps->submit($request->user(), $this->draft($developerApp));

        return $this->show($request, $developerApp);
    }

    public function withdraw(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $version = MarketplaceAppVersion::query()->where('app_id', $developerApp->id)->where('status', MarketplaceAppVersion::SUBMITTED)->first()
            ?? throw new ApiException(409, 'version_not_submitted', __('marketplace.errors.version_not_submitted'));
        $this->apps->withdraw($version);

        return $this->show($request, $developerApp);
    }

    public function newVersion(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $this->apps->newVersion($developerApp);

        return $this->show($request, $developerApp)->setStatusCode(201);
    }

    public function rotateClientSecret(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        return $this->withSecrets($developerApp, ['client_secret' => $this->apps->rotateClientSecret($developerApp)]);
    }

    public function rotateWebhookSecret(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        return $this->withSecrets($developerApp, ['webhook_secret' => $this->apps->rotateWebhookSecret($developerApp)]);
    }

    public function archive(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $this->apps->archive($developerApp, true);

        return $this->show($request, $developerApp);
    }

    public function restore(DeveloperRequest $request, MarketplaceApp $developerApp): JsonResponse
    {
        $this->apps->archive($developerApp, false);

        return $this->show($request, $developerApp);
    }

    private function developer(): MarketplaceDeveloper
    {
        return $this->developers->current() ?? throw new ApiException(409, 'developer_not_registered', __('marketplace.errors.developer_not_registered'));
    }

    private function draft(MarketplaceApp $app): MarketplaceAppVersion
    {
        return MarketplaceAppVersion::query()->where('app_id', $app->id)->where('status', MarketplaceAppVersion::DRAFT)->first()
            ?? throw new ApiException(409, 'version_not_draft', __('marketplace.errors.version_not_draft'));
    }

    /** @param  array<string, string>  $secrets */
    private function withSecrets(MarketplaceApp $app, array $secrets, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->presenter->developerApp($app->load('versions')), 'secrets' => $secrets], $status))
            ->header('Cache-Control', 'no-store');
    }
}
