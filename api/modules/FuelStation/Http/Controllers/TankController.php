<?php

namespace Modules\FuelStation\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Paged;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\ListDeliveriesRequest;
use Modules\FuelStation\Http\Requests\ListDipsRequest;
use Modules\FuelStation\Http\Requests\ListReconciliationsRequest;
use Modules\FuelStation\Http\Requests\ShowDeliveryRequest;
use Modules\FuelStation\Http\Requests\ShowReconciliationRequest;
use Modules\FuelStation\Http\Requests\StoreDeliveryRequest;
use Modules\FuelStation\Http\Requests\StoreDipRequest;
use Modules\FuelStation\Models\Delivery;
use Modules\FuelStation\Models\Dip;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\Access;
use Modules\FuelStation\Tanks\Deliveries;
use Modules\FuelStation\Tanks\Dips;

/**
 * FUEL-23 to FUEL-26: tank dips, the reconciliations they trigger (the
 * variance review of tanks; approval is the workflow engine's) and fuel
 * deliveries. Dips and deliveries are append-only.
 */
class TankController
{
    public function __construct(
        private readonly Access $access,
        private readonly Present $present,
        private readonly Dips $dips,
        private readonly Deliveries $deliveries,
    ) {}

    public function dips(ListDipsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Dip::query(), $request->user(), 'fuel.dip.view')->orderByDesc('taken_at')->orderByDesc('id');
        $this->filter($query, $request, 'taken_at');

        foreach (['tank_id', 'kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        return Paged::respond($query, $request, fn (Dip $d) => $this->present->dip($d));
    }

    public function storeDip(StoreDipRequest $request): JsonResponse
    {
        $tank = Tank::query()->findOrFail($request->validated('tank_id'));
        $dip = $this->dips->record($tank, [
            'kind' => $request->validated('kind', 'routine'), 'dip_cm' => $request->validated('dip_cm'), 'water_cm' => $request->validated('water_cm'),
            'taken_at' => ($request->validated('taken_at') ?? CarbonImmutable::now()->toIso8601String()),
        ], $request->user(), 'web');
        $reconciliation = Reconciliation::query()->where('dip_id', $dip->id)->first();

        return response()->json(['data' => [...$this->present->dip($dip), 'reconciliation' => $reconciliation === null ? null : $this->present->reconciliation($reconciliation)]], 201);
    }

    public function reconciliations(ListReconciliationsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Reconciliation::query(), $request->user(), 'fuel.variance.view')->orderByDesc('created_at')->orderByDesc('id');
        $this->filter($query, $request, 'created_at');

        foreach (['tank_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        return Paged::respond($query, $request, fn (Reconciliation $r) => $this->present->reconciliation($r));
    }

    public function showReconciliation(ShowReconciliationRequest $request, Reconciliation $fuelReconciliation): JsonResponse
    {
        return response()->json(['data' => $this->present->reconciliation($fuelReconciliation)]);
    }

    public function deliveries(ListDeliveriesRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Delivery::query(), $request->user(), 'fuel.delivery.view')->orderByDesc('received_at')->orderByDesc('id');
        $this->filter($query, $request, 'received_at');

        if ($request->filled('tank_id')) {
            $query->where('tank_id', $request->validated('tank_id'));
        }

        return Paged::respond($query, $request, fn (Delivery $d) => $this->present->delivery($d));
    }

    public function showDelivery(ShowDeliveryRequest $request, Delivery $fuelDelivery): JsonResponse
    {
        return response()->json(['data' => $this->present->delivery($fuelDelivery)]);
    }

    public function storeDelivery(StoreDeliveryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $after = $data['after_taken_at'] ?? CarbonImmutable::now()->toIso8601String();
        $delivery = $this->deliveries->record([...$data, 'after_taken_at' => $after, 'before_taken_at' => $data['before_taken_at'] ?? null], $request->user());

        return response()->json(['data' => $this->present->delivery($delivery)], 201);
    }

    /** The location and period filters shared by the lists (days, UTC). */
    private function filter($query, $request, string $column): void
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
