<?php

namespace Modules\FixedAssets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\FixedAssets\Http\Requests\ArchiveScheduleRequest;
use Modules\FixedAssets\Http\Requests\ListSchedulesRequest;
use Modules\FixedAssets\Http\Requests\ListWorkOrdersRequest;
use Modules\FixedAssets\Http\Requests\ShowWorkOrderRequest;
use Modules\FixedAssets\Http\Requests\StoreScheduleRequest;
use Modules\FixedAssets\Http\Requests\StoreWorkOrderRequest;
use Modules\FixedAssets\Http\Requests\UpdateScheduleRequest;
use Modules\FixedAssets\Http\Requests\UpdateWorkOrderRequest;
use Modules\FixedAssets\Http\Resources\RecordResources;
use Modules\FixedAssets\Maintenance\Maintenance;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\MaintenanceSchedule;
use Modules\FixedAssets\Models\WorkOrder;

/** FA-19: maintenance schedules and work orders of the assets the reader may maintain. */
class MaintenanceController
{
    private const ORDER = ['asset', 'schedule', 'assignee', 'vendor', 'creator'];

    public function __construct(private readonly Maintenance $maintenance) {}

    public function schedules(ListSchedulesRequest $request): AnonymousResourceCollection
    {
        $query = MaintenanceSchedule::query()->with('asset');
        $request->applyAssetFilters($query);
        $status = $request->validated('status');
        match ($status) {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->whereNull('archived_at'),
        };

        return AssetController::page($query->orderByRaw('coalesce(next_due_on, date \'9999-12-31\')')->orderBy('id')->paginate($request->perPage())->withQueryString(),
            fn (MaintenanceSchedule $schedule) => RecordResources::schedule($request, $schedule));
    }

    public function storeSchedule(StoreScheduleRequest $request, Asset $faAsset): JsonResponse
    {
        return response()->json(['data' => RecordResources::schedule($request, $this->maintenance->schedule($faAsset, $request->validated())->load('asset'))], 201);
    }

    public function updateSchedule(UpdateScheduleRequest $request, MaintenanceSchedule $faSchedule): JsonResponse
    {
        return response()->json(['data' => RecordResources::schedule($request, $this->maintenance->updateSchedule($faSchedule, $request->validated())->load('asset'))]);
    }

    public function archiveSchedule(ArchiveScheduleRequest $request, MaintenanceSchedule $faSchedule): JsonResponse
    {
        return response()->json(['data' => RecordResources::schedule($request, $this->maintenance->archiveSchedule($faSchedule)->load('asset'))]);
    }

    public function orders(ListWorkOrdersRequest $request): AnonymousResourceCollection
    {
        $query = WorkOrder::query()->with(self::ORDER);
        $request->applyAssetFilters($query, 'due_on');

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('title', 'ilike', $like));
        }

        return AssetController::page($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString(),
            fn (WorkOrder $order) => RecordResources::workOrder($request, $order));
    }

    public function storeOrder(StoreWorkOrderRequest $request, Asset $faAsset): JsonResponse
    {
        $order = $this->maintenance->createOrder($faAsset, $request->validated(), $request->user());

        return response()->json(['data' => RecordResources::workOrder($request, $order->load(self::ORDER))], 201);
    }

    public function order(ShowWorkOrderRequest $request, WorkOrder $faWorkOrder): JsonResponse
    {
        return response()->json(['data' => RecordResources::workOrder($request, $faWorkOrder->load(self::ORDER))]);
    }

    public function updateOrder(UpdateWorkOrderRequest $request, WorkOrder $faWorkOrder): JsonResponse
    {
        $order = $this->maintenance->updateOrder($faWorkOrder, $request->validated(), $request->user());

        return response()->json(['data' => RecordResources::workOrder($request, $order->load(self::ORDER))]);
    }
}
