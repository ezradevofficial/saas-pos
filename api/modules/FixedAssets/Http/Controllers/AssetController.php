<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Assets\Assets;
use Modules\FixedAssets\Assets\Custodians;
use Modules\FixedAssets\Depreciation\Schedule;
use Modules\FixedAssets\Http\Lists\AssetList;
use Modules\FixedAssets\Http\Requests\ActivateAssetRequest;
use Modules\FixedAssets\Http\Requests\CancelAssetRequest;
use Modules\FixedAssets\Http\Requests\CustodiansRequest;
use Modules\FixedAssets\Http\Requests\EstimatesRequest;
use Modules\FixedAssets\Http\Requests\ListAssetsRequest;
use Modules\FixedAssets\Http\Requests\ShowAssetRequest;
use Modules\FixedAssets\Http\Requests\StoreAssetRequest;
use Modules\FixedAssets\Http\Requests\StoreReadingRequest;
use Modules\FixedAssets\Http\Requests\UpdateAssetRequest;
use Modules\FixedAssets\Http\Resources\AssetResource;
use Modules\FixedAssets\Http\Resources\RecordResources;
use Modules\FixedAssets\Http\Resources\Values;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\AssetEvent;
use Modules\FixedAssets\Models\Reading;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FA-04 to FA-06, FA-11, FA-18, FA-26: the register at the reader's places (RBAC-04). */
class AssetController
{
    public function __construct(private readonly Assets $assets) {}

    public function index(ListAssetsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Asset::query()->with(AssetList::RELATIONS);
        $request->applyRegisterFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AssetResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = $this->assets->create($request->validated(), $request->user());

        return AssetResource::make($asset->load(AssetList::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(ShowAssetRequest $request, Asset $faAsset): AssetResource
    {
        return AssetResource::make($faAsset->load(AssetList::RELATIONS));
    }

    public function update(UpdateAssetRequest $request, Asset $faAsset): AssetResource
    {
        return AssetResource::make($this->assets->update($faAsset, $request->validated(), $request->user())->load(AssetList::RELATIONS));
    }

    public function activate(ActivateAssetRequest $request, Asset $faAsset): AssetResource
    {
        return AssetResource::make($this->assets->activate($faAsset, $request->validated(), $request->user())->load(AssetList::RELATIONS));
    }

    public function cancel(CancelAssetRequest $request, Asset $faAsset): AssetResource
    {
        return AssetResource::make($this->assets->cancel($faAsset, $request->user(), (string) $request->validated('reason'))->load(AssetList::RELATIONS));
    }

    public function estimates(EstimatesRequest $request, Asset $faAsset): AssetResource
    {
        return AssetResource::make($this->assets->estimates($faAsset, $request->validated(), $request->user())->load(AssetList::RELATIONS));
    }

    /** FA-26: posted charges and the projection with the current terms. */
    public function schedule(ShowAssetRequest $request, Asset $faAsset, Schedule $schedule): JsonResponse
    {
        $data = $schedule->of($faAsset);
        $currency = (string) ($faAsset->base_currency ?? $faAsset->currency);

        if (! Values::at($request, $faAsset->location_id)) {
            $strip = fn (array $rows) => array_map(fn (array $row) => [...$row, 'amount' => null, 'accumulated' => null, 'book_value' => null], $rows);
            $data = [...$data, 'posted' => $strip($data['posted']), 'projected' => $strip($data['projected'])];
        }

        return response()->json(['data' => [...$data, 'currency' => $currency]]);
    }

    public function history(ShowAssetRequest $request, Asset $faAsset): JsonResponse
    {
        $values = Values::at($request, $faAsset->location_id);
        $events = AssetEvent::query()->with('user')->where('asset_id', $faAsset->id)->orderByDesc('created_at')->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $events->map(fn (AssetEvent $event) => RecordResources::event($request, $event, $values))->all()]);
    }

    public function readings(ShowAssetRequest $request, Asset $faAsset): JsonResponse
    {
        $rows = Reading::query()->with('recorder')->where('asset_id', $faAsset->id)->orderByDesc('read_on')->orderByDesc('reading')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Reading $reading) => RecordResources::reading($reading))->all()]);
    }

    public function read(StoreReadingRequest $request, Asset $faAsset): JsonResponse
    {
        $reading = $this->assets->read($faAsset, (string) $request->validated('read_on'), (string) $request->validated('reading'), $request->validated('note'), $request->user());

        return response()->json(['data' => RecordResources::reading($reading->load('recorder'))], 201);
    }

    /** FA-04: who may hold assets of a company: the tenant's active users and, while HR is active, the company's employees. */
    public function custodians(CustodiansRequest $request, Custodians $custodians): JsonResponse
    {
        $company = $request->company();
        $search = trim((string) $request->validated('search', ''));
        $users = User::query()->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')->orderBy('id')->limit(50)->get()
            ->map(fn (User $user) => ['type' => 'user', 'id' => $user->id, 'name' => $user->name])->values()->all();
        $employees = array_map(fn (array $row) => ['type' => 'employee', ...$row], $custodians->searchEmployees($company->id, $search));

        return response()->json(['data' => [...$users, ...$employees], 'hr' => $custodians->hrActive()]);
    }

    public static function page(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, callable $shape): AnonymousResourceCollection
    {
        return JsonResource::collection($paginator->through($shape));
    }
}
