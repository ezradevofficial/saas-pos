<?php

namespace App\Core\MasterData\Prices\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Prices\Http\Lists\ItemPriceList;
use App\Core\MasterData\Prices\Http\Requests\BulkSetItemPricesRequest;
use App\Core\MasterData\Prices\Http\Requests\ItemPriceActionRequest;
use App\Core\MasterData\Prices\Http\Requests\ListItemPricesRequest;
use App\Core\MasterData\Prices\Http\Requests\SetItemPriceRequest;
use App\Core\MasterData\Prices\Http\Resources\ItemPriceResource;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceWriter;
use App\Core\MasterData\Taxes\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-03 follow-up: item prices per price list, per unit, effective-dated,
 * with optional quantity breaks; set one or up to 500 at once (all or
 * nothing), archive and restore (TEN-06, never deleted). Every change is
 * audited (`core.item_price.*`) and shows in the item's and the price
 * list's history (MD-07). Who: `core.price.view|edit` at the list's
 * company (PriceAccess); field rules may hide or freeze prices (RBAC-05).
 */
class ItemPriceController
{
    private const RELATIONS = ['item:id,code,name', 'uom:id,code'];

    public function __construct(private readonly PriceWriter $writer) {}

    public function index(ListItemPricesRequest $request, PriceList $priceList, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $day = PriceWriter::today($priceList);
        $query = ItemPriceList::query($priceList, $day)->with(self::RELATIONS);

        if ($request->filled('state')) {
            ItemPriceList::whereState($query, $request->validated('state'), $day);
        }

        $request->applySearch($request->applyStatus($query), ['item_code' => 'items.code', 'item_name' => 'items.name', 'uom_code' => 'uoms.code']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ItemPriceResource::collection($query->paginate($request->perPage())->withQueryString())
            ->additional(['meta' => ['today' => $day, 'currency' => $priceList->currency]]);
    }

    /** 201 when a price was added, 200 when an active one got the new amount. */
    public function store(SetItemPriceRequest $request, PriceList $priceList): JsonResponse
    {
        [$result] = $this->writer->write($priceList, [$request->validated()]);

        return ItemPriceResource::make($result['price']->refresh()->load(self::RELATIONS))
            ->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function bulk(BulkSetItemPricesRequest $request, PriceList $priceList): JsonResponse
    {
        $results = $this->writer->write($priceList, $request->validated('prices'), 'prices');
        $ids = array_map(fn (array $result) => $result['price']->id, $results);
        $prices = ItemPrice::query()->whereIn('id', $ids)->with(self::RELATIONS)->get()->sortBy(fn (ItemPrice $price) => array_search($price->id, $ids, true))->values();

        return ItemPriceResource::collection($prices)->additional(['meta' => [
            'created' => count(array_filter($results, fn (array $result) => $result['created'])),
            'updated' => count(array_filter($results, fn (array $result) => ! $result['created'])),
        ]])->response();
    }

    public function archive(ItemPriceActionRequest $request, ItemPrice $itemPrice): ItemPriceResource
    {
        if (! $itemPrice->isArchived()) {
            $itemPrice->archive();
        }

        return ItemPriceResource::make($itemPrice->load(self::RELATIONS));
    }

    public function restore(ItemPriceActionRequest $request, ItemPrice $itemPrice): ItemPriceResource
    {
        return ItemPriceResource::make($this->writer->restore($itemPrice)->load(self::RELATIONS));
    }
}
