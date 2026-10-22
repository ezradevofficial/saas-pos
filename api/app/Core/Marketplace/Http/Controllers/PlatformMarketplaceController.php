<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Marketplace\AppReview;
use App\Core\Marketplace\Http\MarketplacePresenter;
use App\Core\Marketplace\Http\Requests\PlatformMarketplaceRequest;
use App\Core\Marketplace\Http\Requests\RejectVersionRequest;
use App\Core\Marketplace\Http\Requests\ReviewVersionRequest;
use App\Core\Marketplace\Http\Requests\SuspendAppRequest;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Http\JsonResponse;

/**
 * ADM-05: the review queue in the staff console. Staff read every app and
 * version on the platform connection (no tenant data: apps are global),
 * see what a submitted version adds to the listed one (scopes, events,
 * redirect URIs, price), and approve, reject, suspend or reinstate.
 */
class PlatformMarketplaceController
{
    public function __construct(
        private readonly AppReview $review,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(PlatformMarketplaceRequest $request): JsonResponse
    {
        $query = MarketplaceApp::on(PlatformDatabase::CONNECTION)->with(['developer', 'versions']);

        match ($request->validated('status', 'submitted')) {
            'submitted' => $query->whereHas('versions', fn ($q) => $q->where('status', MarketplaceAppVersion::SUBMITTED)),
            'listed' => $query->whereNotNull('listed_version_id')->where('status', '<>', MarketplaceApp::SUSPENDED),
            'suspended' => $query->where('status', MarketplaceApp::SUSPENDED),
            default => null,
        };

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('slug', 'ilike', $like)->orWhereHas('versions', fn ($v) => $v->where('name', 'ilike', $like)));
        }

        $apps = $query->orderByDesc('updated_at')->limit(200)->get();

        return new JsonResponse(['data' => $apps->map(fn (MarketplaceApp $app) => $this->summary($app))->all()]);
    }

    public function show(PlatformMarketplaceRequest $request, string $app): JsonResponse
    {
        $model = MarketplaceApp::on(PlatformDatabase::CONNECTION)->with(['developer', 'versions'])->findOrFail($app);
        $listed = $model->versions->firstWhere('id', $model->listed_version_id);
        $submitted = $model->versions->firstWhere('status', MarketplaceAppVersion::SUBMITTED);
        $client = OAuthClient::on(PlatformDatabase::CONNECTION)->find($model->client_id);

        return new JsonResponse(['data' => [
            ...$this->summary($model),
            'developer' => $model->developer?->only(['id', 'name', 'email', 'website', 'status', 'first_party', 'developer_tenant_id']),
            'client' => ['id' => $client?->id, 'confidential' => $client?->confidential() ?? false, 'redirect_uris' => $client?->redirect_uris ?? [], 'revoked' => (bool) $client?->revoked],
            'versions' => $model->versions->sortByDesc('version')->values()->map(fn (MarketplaceAppVersion $version) => $this->presenter->version($version))->all(),
            'changes' => $submitted === null ? null : self::changes($listed, $submitted),
        ]]);
    }

    public function approve(ReviewVersionRequest $request, string $version): JsonResponse
    {
        $model = MarketplaceAppVersion::on(PlatformDatabase::CONNECTION)->findOrFail($version);
        $this->review->approve($request->staff(), $model, $request->validated('note'), (string) $request->validated('submitted_at'));

        return $this->show(PlatformMarketplaceRequest::createFrom($request), $model->app_id);
    }

    public function reject(RejectVersionRequest $request, string $version): JsonResponse
    {
        $model = MarketplaceAppVersion::on(PlatformDatabase::CONNECTION)->findOrFail($version);
        $this->review->reject($request->staff(), $model, $request->validated('reason'));

        return $this->show(PlatformMarketplaceRequest::createFrom($request), $model->app_id);
    }

    public function suspend(SuspendAppRequest $request, string $app): JsonResponse
    {
        $model = MarketplaceApp::on(PlatformDatabase::CONNECTION)->findOrFail($app);
        $this->review->suspend($request->staff(), $model, $request->validated('reason'));

        return $this->show(PlatformMarketplaceRequest::createFrom($request), $model->id);
    }

    public function reinstate(SuspendAppRequest $request, string $app): JsonResponse
    {
        $model = MarketplaceApp::on(PlatformDatabase::CONNECTION)->findOrFail($app);
        $this->review->reinstate($request->staff(), $model, $request->validated('reason'));

        return $this->show(PlatformMarketplaceRequest::createFrom($request), $model->id);
    }

    /** @return array<string, mixed> */
    private function summary(MarketplaceApp $app): array
    {
        $latest = $app->versions->sortByDesc('version')->first();

        return [
            'id' => $app->id,
            'slug' => $app->slug,
            'status' => $app->status,
            'name' => $latest?->name,
            'category' => $latest?->category,
            'developer_name' => $app->developer?->name,
            'first_party' => (bool) $app->developer?->first_party,
            'listed_version' => $app->versions->firstWhere('id', $app->listed_version_id)?->version,
            'latest_version' => $latest?->version,
            'latest_status' => $latest?->status,
            'submitted_at' => $latest?->submitted_at?->toIso8601String(),
            'suspended_reason' => $app->suspended_reason,
        ];
    }

    /** @return array<string, mixed> what a submitted version changes against the listed one */
    private static function changes(?MarketplaceAppVersion $listed, MarketplaceAppVersion $submitted): array
    {
        $diff = fn (array $new, array $old) => ['added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];

        return [
            'scopes' => $diff($submitted->scopeList(), $listed?->scopeList() ?? []),
            'webhook_events' => $diff($submitted->eventList(), $listed?->eventList() ?? []),
            'redirect_uris' => $diff($submitted->redirectList(), $listed?->redirectList() ?? []),
            'pricing_changed' => $listed !== null && $listed->pricing()->toArray() !== $submitted->pricing()->toArray(),
            'needs_consent' => $listed !== null && (array_diff($submitted->scopeList(), $listed->scopeList()) !== [] || array_diff($submitted->eventList(), $listed->eventList()) !== []),
        ];
    }
}
