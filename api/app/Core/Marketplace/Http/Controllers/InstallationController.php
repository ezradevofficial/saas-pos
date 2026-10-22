<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Api\ApiUsage;
use App\Core\Marketplace\Http\MarketplacePresenter;
use App\Core\Marketplace\Http\Requests\ConsentInstallationRequest;
use App\Core\Marketplace\Http\Requests\UninstallAppRequest;
use App\Core\Marketplace\Http\Requests\ViewMarketplaceRequest;
use App\Core\Marketplace\Installations;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use Illuminate\Http\JsonResponse;

/**
 * MKT-02: Settings, Installed apps: what each app was given (scopes in
 * plain words, the role and scope it acts as, its webhook events, its
 * price), whether a newer version waits for consent, its API usage, and
 * uninstalling.
 */
class InstallationController
{
    private const RELATIONS = ['app.listedVersion', 'version', 'installer'];

    public function __construct(
        private readonly Installations $installations,
        private readonly MarketplacePresenter $presenter,
        private readonly ApiUsage $usage,
    ) {}

    public function index(ViewMarketplaceRequest $request): JsonResponse
    {
        $installations = AppInstallation::query()->with(self::RELATIONS)->orderByRaw("status = 'active' desc")->orderByDesc('installed_at')->get();

        return new JsonResponse(['data' => $installations->map(fn (AppInstallation $installation) => $this->presenter->installation($installation))->all()]);
    }

    public function show(ViewMarketplaceRequest $request, AppInstallation $appInstallation): JsonResponse
    {
        $series = $this->usage->perDay(30, $appInstallation->api_key_id);

        return new JsonResponse(['data' => [
            ...$this->presenter->installation($appInstallation->load(self::RELATIONS)),
            'usage' => ['days' => $series, 'total' => array_sum(array_column($series, 'requests'))],
        ]]);
    }

    public function consent(ConsentInstallationRequest $request, AppInstallation $appInstallation): JsonResponse
    {
        abort_unless($appInstallation->isActive(), 404);
        $app = MarketplaceApp::query()->with('listedVersion')->findOrFail($appInstallation->app_id);
        $installation = $this->installations->consent($request->user(), $app, $request->validated('role_id'), $request->validated('scope_type'), $request->validated('scope_id'));

        return new JsonResponse(['data' => $this->presenter->installation($installation->load(self::RELATIONS))]);
    }

    public function uninstall(UninstallAppRequest $request, AppInstallation $appInstallation): JsonResponse
    {
        $installation = $this->installations->uninstall($request->user(), $appInstallation);

        return new JsonResponse(['data' => $this->presenter->installation($installation->load(self::RELATIONS))]);
    }
}
