<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\MasterData\Items\Item;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Http\Requests\ArchiveRequest;
use Modules\Salon\Http\Requests\StorePackageTypeRequest;
use Modules\Salon\Http\Requests\StoreServiceRequest;
use Modules\Salon\Http\Requests\UpdatePackageTypeRequest;
use Modules\Salon\Http\Requests\UpdateServiceRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Service;
use Modules\Salon\Support\Catalogue;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;

/** SAL-02, SAL-18: the service menu and the package types sold from it. */
class ServiceController
{
    public function __construct(private readonly Places $places, private readonly Views $views, private readonly Catalogue $catalogue) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Service::query()->orderBy('category')->orderBy('name')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->filled('kind') && $query->where('kind', $request->input('kind'));
        $request->boolean('archived') || $query->whereNull('archived_at');

        return response()->json(['data' => $this->views->services($query->get())]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $item = $this->catalogue->item($data['company_id'], $data['item_id']);
        $this->catalogue->assertItemFree($data['company_id'], $item->id);
        $clean = $this->catalogue->service($data['company_id'], $data);

        $service = Errors::named(fn () => Service::create([...$clean, 'name' => $clean['name'] ?? $item->name]));

        return response()->json(['data' => $this->views->services(collect([$service->refresh()]))[0]], 201);
    }

    public function update(UpdateServiceRequest $request, Service $salonService): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['item_id']) && $data['item_id'] !== $salonService->item_id) {
            $item = $this->catalogue->item($salonService->company_id, $data['item_id']);
            $this->catalogue->assertItemFree($salonService->company_id, $item->id, $salonService->id);
        }

        $clean = $this->catalogue->service($salonService->company_id, $data, $salonService->id);
        Errors::named(fn () => $salonService->fill($clean)->save());

        return response()->json(['data' => $this->views->services(collect([$salonService->refresh()]))[0]]);
    }

    public function archive(ArchiveRequest $request, Service $salonService): JsonResponse
    {
        $salonService->isArchived() || $salonService->archive();

        return response()->json(['data' => $this->views->services(collect([$salonService->refresh()]))[0]]);
    }

    public function types(ViewRequest $request): JsonResponse
    {
        $query = PackageType::query()->orderBy('name')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->boolean('archived') || $query->whereNull('archived_at');

        return response()->json(['data' => $this->views->packageTypes($query->get())]);
    }

    public function storeType(StorePackageTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $item = $this->catalogue->item($data['company_id'], $data['item_id']);
        $this->catalogue->assertItemFree($data['company_id'], $item->id);
        $data['service_ids'] = $this->catalogue->coveredServices($data['company_id'], (array) ($data['service_ids'] ?? []));

        $type = Errors::named(fn () => PackageType::create($data));

        return response()->json(['data' => $this->views->packageTypes(collect([$type->refresh()]))[0]], 201);
    }

    public function updateType(UpdatePackageTypeRequest $request, PackageType $salonPtype): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('service_ids', $data)) {
            $data['service_ids'] = $this->catalogue->coveredServices($salonPtype->company_id, (array) $data['service_ids']);
        }

        Errors::named(fn () => $salonPtype->fill($data)->save());

        return response()->json(['data' => $this->views->packageTypes(collect([$salonPtype->refresh()]))[0]]);
    }

    public function archiveType(ArchiveRequest $request, PackageType $salonPtype): JsonResponse
    {
        $salonPtype->isArchived() || $salonPtype->archive();

        return response()->json(['data' => $this->views->packageTypes(collect([$salonPtype->refresh()]))[0]]);
    }

    /** Items a service can be made from: the company's service-like items not yet on the menu. */
    public function items(ViewRequest $request): JsonResponse
    {
        $companyId = (string) ($request->input('company_id') ?: $this->places->defaultCompany($request->user(), $request->permission()) ?? throw Errors::make(422, 'company_required', field: 'company_id'));
        $taken = Service::query()->where('company_id', $companyId)->whereNull('archived_at')->pluck('item_id')
            ->merge(PackageType::query()->where('company_id', $companyId)->whereNull('archived_at')->pluck('item_id'));
        $items = Item::query()->whereNull('archived_at')->whereIn('type', ['service', 'non_stock'])->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->whereNotIn('id', $taken)->orderBy('name')->limit(200)->get(['id', 'code', 'name']);

        return response()->json(['data' => $items->map(fn (Item $i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name])->values()->all()]);
    }
}
