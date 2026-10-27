<?php

namespace Modules\FuelStation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Paged;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\ApproveMeterResetRequest;
use Modules\FuelStation\Http\Requests\ListMeterResetsRequest;
use Modules\FuelStation\Http\Requests\RejectMeterResetRequest;
use Modules\FuelStation\Http\Requests\StoreMeterResetRequest;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Shifts\MeterResets;
use Modules\FuelStation\Support\Access;

/** FUEL-17: meter reset requests and their decisions (never by the requester); audited. */
class MeterResetController
{
    public function __construct(private readonly Access $access, private readonly Present $present, private readonly MeterResets $resets) {}

    public function index(ListMeterResetsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(MeterReset::query(), $request->user(), 'fuel.variance.view')->orderByDesc('requested_at')->orderByDesc('id');

        foreach (['location_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        return Paged::respond($query, $request, fn (MeterReset $r) => $this->present->meterReset($r));
    }

    public function store(StoreMeterResetRequest $request): JsonResponse
    {
        $nozzle = Nozzle::query()->findOrFail($request->validated('nozzle_id'));
        $reset = $this->resets->request($nozzle, $request->validated(), $request->user(), true);

        return response()->json(['data' => $this->present->meterReset($reset)], 201);
    }

    public function approve(ApproveMeterResetRequest $request, MeterReset $fuelReset): JsonResponse
    {
        return response()->json(['data' => $this->present->meterReset($this->resets->approve($fuelReset, $request->user()))]);
    }

    public function reject(RejectMeterResetRequest $request, MeterReset $fuelReset): JsonResponse
    {
        return response()->json(['data' => $this->present->meterReset($this->resets->reject($fuelReset, $request->user()))]);
    }
}
