<?php

namespace App\Core\Billing\Catalogue\Http\Controllers;

use App\Core\Billing\Catalogue\Http\CataloguePresenter;
use App\Core\Billing\Catalogue\Http\Requests\ManageCatalogueRequest;
use App\Core\Billing\Catalogue\Http\Requests\StoreCatalogueModuleRequest;
use App\Core\Billing\Catalogue\Http\Requests\UpdateCatalogueModuleRequest;
use App\Core\Billing\Catalogue\Http\Requests\ViewCatalogueRequest;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Platform\PlatformAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** SUB-01: catalogue modules, for platform staff. Archived, never deleted. */
class CatalogueModuleController extends Controller
{
    public function __construct(private readonly PlatformAuditor $audit) {}

    public function index(ViewCatalogueRequest $request): JsonResponse
    {
        $prices = CataloguePrice::live()->where('item_type', CataloguePrice::MODULE)->get()->groupBy('item_key');
        $modules = CatalogueModule::query()->orderBy('sort')->orderBy('key')->get();

        return response()->json(['data' => $modules->map(fn (CatalogueModule $m) => CataloguePresenter::module($m, $prices->get($m->key, collect())))->all()]);
    }

    public function store(StoreCatalogueModuleRequest $request): JsonResponse
    {
        $module = DB::transaction(function () use ($request) {
            $module = CatalogueModule::create([
                ...Arr::only($request->validated(), ['key', 'kind', 'unit', 'name', 'requires', 'sort']),
                'includes' => (object) ($request->validated('includes') ?? []),
            ]);
            $this->audit->record('catalogue.module.created', $module, null, CataloguePresenter::module($module));

            return $module;
        });

        return response()->json(['data' => CataloguePresenter::module($module, collect())], 201);
    }

    public function update(UpdateCatalogueModuleRequest $request, CatalogueModule $catalogueModule): JsonResponse
    {
        DB::transaction(function () use ($request, $catalogueModule) {
            $before = CataloguePresenter::module($catalogueModule);
            $catalogueModule->fill(Arr::only($request->validated(), ['name', 'requires', 'sort']));

            if ($request->has('includes')) {
                $catalogueModule->includes = (object) ($request->validated('includes') ?? []);
            }

            $catalogueModule->save();
            $this->audit->record('catalogue.module.updated', $catalogueModule, $before, CataloguePresenter::module($catalogueModule));
        });

        return response()->json(['data' => CataloguePresenter::module($catalogueModule)]);
    }

    public function archive(ManageCatalogueRequest $request, CatalogueModule $catalogueModule): JsonResponse
    {
        return $this->setArchived($catalogueModule, true);
    }

    public function restore(ManageCatalogueRequest $request, CatalogueModule $catalogueModule): JsonResponse
    {
        return $this->setArchived($catalogueModule, false);
    }

    private function setArchived(CatalogueModule $module, bool $archived): JsonResponse
    {
        DB::transaction(function () use ($module, $archived) {
            $module->forceFill(['archived_at' => $archived ? now() : null])->save();
            $this->audit->record($archived ? 'catalogue.module.archived' : 'catalogue.module.restored', $module);
        });

        return response()->json(['data' => CataloguePresenter::module($module)]);
    }
}
