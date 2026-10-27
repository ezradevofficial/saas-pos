<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\CompanyClock;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pharmacy\Http\Requests\FefoRequest;
use Modules\Pharmacy\Http\Requests\ListLotsRequest;
use Modules\Pharmacy\Http\Requests\LotStatusRequest;
use Modules\Pharmacy\Http\Requests\ShowLotRequest;
use Modules\Pharmacy\Http\Resources\LotResource;
use Modules\Pharmacy\Http\Resources\Refs;
use Modules\Pharmacy\Http\Resources\StockResource;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Support\Qty;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PHA-04, PHA-05, PHA-07, PHA-11: lots, stock by lot, first-expiry-first-out and quarantine. */
class LotController
{
    public const DETAIL = ['item', 'company', 'supplier', 'balances.location'];

    public function __construct(private readonly Lots $lots, private readonly Places $places) {}

    public function index(ListLotsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->builder();

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return StockResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowLotRequest $request, Lot $pharmacyLot): LotResource
    {
        return LotResource::make($pharmacyLot->load(self::DETAIL))->detail();
    }

    public function quarantine(LotStatusRequest $request, Lot $pharmacyLot): LotResource
    {
        return LotResource::make($this->lots->quarantine($pharmacyLot, (string) $request->validated('reason'), $request->user())->load(self::DETAIL))->detail();
    }

    public function release(LotStatusRequest $request, Lot $pharmacyLot): LotResource
    {
        return LotResource::make($this->lots->release($pharmacyLot, (string) $request->validated('reason'), $request->user())->load(self::DETAIL))->detail();
    }

    /** PHA-07: the lots that may be sold at a place, earliest expiry first, and how to cover a quantity from them. */
    public function fefo(FefoRequest $request): JsonResponse
    {
        $place = $this->places->require($request->validated('location_id'));
        $settings = Settings::for($place['company_id']);
        $today = CompanyClock::today($place['company_id'], $place['branch_id']);
        $wanted = $request->filled('qty') ? Qty::of($request->validated('qty')) : null;
        $result = $this->lots->suggest($request->validated('item_id'), $request->validated('location_id'), $settings, $today, $wanted instanceof BigDecimal ? $wanted : null);

        return new JsonResponse(['data' => [
            'today' => $today,
            'near_expiry_days' => (int) $settings->near_expiry_days,
            'lots' => array_map(fn (array $row) => [
                'lot_id' => $row['lot']->id,
                'batch_number' => $row['lot']->batch_number,
                'expires_on' => $row['lot']->expires_on->toDateString(),
                'days_left' => $row['days_left'],
                'on_hand' => $row['on_hand'],
                'take' => $row['take'],
                'warn' => $row['warn'],
            ], $result['lots']),
            'blocked' => array_map(fn (array $row) => [
                'lot_id' => $row['lot']->id,
                'batch_number' => $row['lot']->batch_number,
                'expires_on' => $row['lot']->expires_on->toDateString(),
                'on_hand' => $row['on_hand'],
                'reason' => $row['reason'],
            ], $result['blocked']),
            'shortfall' => $result['shortfall'],
            'item' => Refs::item(Item::query()->find($request->validated('item_id'))),
        ]]);
    }
}
