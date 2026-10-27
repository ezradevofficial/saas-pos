<?php

namespace Modules\FuelStation\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FuelStation\Http\Paged;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\ListSalesRequest;
use Modules\FuelStation\Http\Requests\ListSettlementsRequest;
use Modules\FuelStation\Http\Requests\ListShiftsRequest;
use Modules\FuelStation\Http\Requests\ShowSettlementRequest;
use Modules\FuelStation\Http\Requests\ShowShiftRequest;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Support\Access;

/**
 * FUEL-09 to FUEL-15, FUEL-18: the back office's view of shifts, the
 * attendants' settlements (the variance review; approval itself is the
 * workflow engine's, in the approvals inbox) and the fuel sales, including
 * the flagged ones. Shifts are opened and closed at the till.
 */
class ShiftController
{
    public function __construct(private readonly Access $access, private readonly Present $present) {}

    public function shifts(ListShiftsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Shift::query(), $request->user(), 'fuel.shift.view')->orderByDesc('opened_at')->orderByDesc('id');
        $this->filter($query, $request, 'opened_at');

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        return Paged::respond($query, $request, fn (Shift $s) => $this->present->shift($s));
    }

    public function showShift(ShowShiftRequest $request, Shift $fuelShift): JsonResponse
    {
        return response()->json(['data' => $this->present->shift($fuelShift, true)]);
    }

    public function settlements(ListSettlementsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Settlement::query(), $request->user(), 'fuel.variance.view')->where('status', '<>', Settlement::OPEN)->orderByDesc('created_at')->orderByDesc('id');
        $this->filter($query, $request, 'created_at');

        foreach (['status', 'attendant_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        return Paged::respond($query, $request, fn (Settlement $s) => $this->present->settlement($s));
    }

    public function showSettlement(ShowSettlementRequest $request, Settlement $fuelSettlement): JsonResponse
    {
        return response()->json(['data' => $this->present->settlement($fuelSettlement, true)]);
    }

    public function sales(ListSalesRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(FuelSale::query(), $request->user(), 'fuel.sale.view')->orderByDesc('sold_at')->orderByDesc('id');
        $this->filter($query, $request, 'sold_at');

        foreach (['shift_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        if ($request->boolean('flagged')) {
            $query->whereRaw("flags <> '[]'::jsonb");
        }

        return Paged::respond($query, $request, fn (FuelSale $s) => $this->present->sale($s));
    }

    /** The location and period filters shared by the lists (days, UTC). */
    private function filter(Builder $query, Request $request, string $column): void
    {
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->query('location_id'));
        }

        if ($request->filled('from')) {
            $query->where($column, '>=', CarbonImmutable::parse($request->query('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where($column, '<', CarbonImmutable::parse($request->query('to'))->addDay()->startOfDay());
        }
    }
}
