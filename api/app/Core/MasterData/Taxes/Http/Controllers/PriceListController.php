<?php

namespace App\Core\MasterData\Taxes\Http\Controllers;

use App\Core\Exports\ListExport;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-03: a company's price lists, tax-inclusive or exclusive. One active
 * default per company and currency: making a list the default unsets the
 * previous one (each change audited). Archived, never deleted (TEN-06); an
 * archived list stops being a default and can't be made one; restoring a
 * former default whose currency has another default meanwhile restores it
 * as a plain list.
 */
class PriceListController
{
    public function __construct(private readonly Archiver $archiver) {}

    public function index(ListPriceListsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applySearch($request->applyStatus(PriceList::query()->where('company_id', $company->id)), ['name' => 'name', 'currency' => 'currency']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PriceListResource::collection($query->paginate($request->perPage())->withQueryString());
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

            if ($list->isArchived() && ($data['is_default'] ?? false)) {
                throw ValidationException::withMessages(['is_default' => __('core.price_list.archived_default')]);
            }

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
        $list = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($priceList) {
            Company::query()->whereKey($priceList->company_id)->lockForUpdate()->firstOrFail();
            $list = PriceList::query()->whereKey($priceList->id)->firstOrFail();

            if (! $list->isArchived()) {
                return $list;
            }

            // Another list became the default meanwhile: this one comes back as a plain list.
            if ($list->is_default && $this->otherDefault($list)->exists()) {
                $list->is_default = false;
            }

            $list->restore();

            return $list;
        });

        return PriceListResource::make($list);
    }

    /** Unset the other active default of the list's company and currency. */
    private function clearDefault(PriceList $list): void
    {
        $this->otherDefault($list)->get()->each(fn (PriceList $other) => $other->fill(['is_default' => false])->save());
    }

    /** The other active default of the list's company and currency. */
    private function otherDefault(PriceList $list): Builder
    {
        return PriceList::query()
            ->where('company_id', $list->company_id)
            ->where('currency', $list->currency)
            ->where('is_default', true)
            ->whereNull('archived_at')
            ->when($list->exists, fn ($q) => $q->whereKeyNot($list->id));
    }
}
