<?php

namespace App\Core\MasterData\Taxes\Http\Controllers;

use App\Core\MasterData\Taxes\Http\Requests\ListPriceListsRequest;
use App\Core\MasterData\Taxes\Http\Requests\PriceListActionRequest;
use App\Core\MasterData\Taxes\Http\Requests\PriceListRequest;
use App\Core\MasterData\Taxes\Http\Requests\StorePriceListRequest;
use App\Core\MasterData\Taxes\Http\Requests\UpdatePriceListRequest;
use App\Core\MasterData\Taxes\Http\Resources\PriceListResource;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * MD-03: a company's price lists, tax-inclusive or exclusive. One active
 * default per company and currency: making a list the default unsets the
 * previous one (each change audited). Archived, never deleted (TEN-06); an
 * archived list stops being a default.
 */
class PriceListController
{
    public function __construct(private readonly Archiver $archiver) {}

    public function index(ListPriceListsRequest $request, Company $company): AnonymousResourceCollection
    {
        $query = PriceList::query()->where('company_id', $company->id);

        return PriceListResource::collection(
            $request->applyStatus($query)->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StorePriceListRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        $list = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data) {
            // Locks the company row: default changes of a company run one at a time.
            $this->archiver->lockActive(Company::class, $company->id);
            $list = new PriceList(['company_id' => $company->id, ...$data]);

            if ($list->is_default) {
                $this->clearDefault($list);
            }

            $list->save();

            return $list;
        });

        return PriceListResource::make($list)->response()->setStatusCode(201);
    }

    public function show(PriceListRequest $request, PriceList $priceList): PriceListResource
    {
        return PriceListResource::make($priceList);
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): PriceListResource
    {
        $data = $request->validated();

        $list = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($priceList, $data) {
            Company::query()->whereKey($priceList->company_id)->lockForUpdate()->firstOrFail();
            $list = PriceList::query()->whereKey($priceList->id)->firstOrFail();
            $list->fill($data);

            if ($list->is_default && ! $list->isArchived()) {
                $this->clearDefault($list);
            }

            $list->save();

            return $list;
        });

        return PriceListResource::make($list);
    }

    public function archive(PriceListActionRequest $request, PriceList $priceList): PriceListResource
    {
        if (! $priceList->isArchived()) {
            $priceList->is_default = false;
            $priceList->archive();
        }

        return PriceListResource::make($priceList);
    }

    public function restore(PriceListActionRequest $request, PriceList $priceList): PriceListResource
    {
        $priceList->restore();

        return PriceListResource::make($priceList);
    }

    /** Unset the other active default of the list's company and currency. */
    private function clearDefault(PriceList $list): void
    {
        PriceList::query()
            ->where('company_id', $list->company_id)
            ->where('currency', $list->currency)
            ->where('is_default', true)
            ->whereNull('archived_at')
            ->when($list->exists, fn ($q) => $q->whereKeyNot($list->id))
            ->get()
            ->each(fn (PriceList $other) => $other->fill(['is_default' => false])->save());
    }
}
