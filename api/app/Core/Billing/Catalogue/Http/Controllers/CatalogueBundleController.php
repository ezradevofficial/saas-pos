<?php

namespace App\Core\Billing\Catalogue\Http\Controllers;

use App\Core\Billing\Catalogue\Http\CataloguePresenter;
use App\Core\Billing\Catalogue\Http\Requests\ManageCatalogueRequest;
use App\Core\Billing\Catalogue\Http\Requests\StoreCatalogueBundleRequest;
use App\Core\Billing\Catalogue\Http\Requests\UpdateCatalogueBundleRequest;
use App\Core\Billing\Catalogue\Http\Requests\ViewCatalogueRequest;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Platform\PlatformAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * SUB-01: bundles and the add-ons each one sells (Brand Pack with
 * Starter), for platform staff. Archived, never deleted.
 */
class CatalogueBundleController extends Controller
{
    private const FIELDS = ['key', 'name', 'modules', 'addons', 'included_outlets', 'quoted', 'sort'];

    public function __construct(private readonly PlatformAuditor $audit) {}

    public function index(ViewCatalogueRequest $request): JsonResponse
    {
        $prices = CataloguePrice::live()->where('item_type', CataloguePrice::BUNDLE)->get()->groupBy('item_key');
        $bundles = CatalogueBundle::query()->orderBy('sort')->orderBy('key')->get();

        return response()->json(['data' => $bundles->map(fn (CatalogueBundle $b) => CataloguePresenter::bundle($b, $prices->get($b->key, collect())))->all()]);
    }

    public function store(StoreCatalogueBundleRequest $request): JsonResponse
    {
        $bundle = DB::transaction(function () use ($request) {
            $bundle = CatalogueBundle::create(['addons' => [], ...Arr::only($request->validated(), self::FIELDS)]);
            $this->audit->record('catalogue.bundle.created', $bundle, null, CataloguePresenter::bundle($bundle));

            return $bundle;
        });

        return response()->json(['data' => CataloguePresenter::bundle($bundle->refresh(), collect())], 201);
    }

    public function update(UpdateCatalogueBundleRequest $request, CatalogueBundle $catalogueBundle): JsonResponse
    {
        DB::transaction(function () use ($request, $catalogueBundle) {
            $before = CataloguePresenter::bundle($catalogueBundle);
            $catalogueBundle->fill(Arr::only($request->validated(), self::FIELDS))->save();
            $this->audit->record('catalogue.bundle.updated', $catalogueBundle, $before, CataloguePresenter::bundle($catalogueBundle));
        });

        return response()->json(['data' => CataloguePresenter::bundle($catalogueBundle)]);
    }

    public function archive(ManageCatalogueRequest $request, CatalogueBundle $catalogueBundle): JsonResponse
    {
        return $this->setArchived($catalogueBundle, true);
    }

    public function restore(ManageCatalogueRequest $request, CatalogueBundle $catalogueBundle): JsonResponse
    {
        return $this->setArchived($catalogueBundle, false);
    }

    private function setArchived(CatalogueBundle $bundle, bool $archived): JsonResponse
    {
        DB::transaction(function () use ($bundle, $archived) {
            $bundle->forceFill(['archived_at' => $archived ? now() : null])->save();
            $this->audit->record($archived ? 'catalogue.bundle.archived' : 'catalogue.bundle.restored', $bundle);
        });

        return response()->json(['data' => CataloguePresenter::bundle($bundle)]);
    }
}
