<?php

namespace Modules\FuelStation\Http\Controllers;

use App\Core\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Requests\DeviceMeterResetsRequest;
use Modules\FuelStation\Http\Requests\DeviceShiftsRequest;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Shifts\MeterResets;
use Modules\FuelStation\Shifts\Rejection;
use Modules\FuelStation\Shifts\ShiftUploads;
use Modules\FuelStation\Support\Stations;
use Modules\POS\Sync\Authority;
use Modules\POS\Sync\DevicePlace;

/**
 * FUEL-22: what a paired till sends (device token only; place from the
 * device). Every upload answers one result per record: 200 when anything is
 * stored, 422 `upload_rejected` when nothing is. Sales are not here: fuel is
 * sold through `pos/sales` (FUEL-18).
 */
class DeviceController
{
    public function shifts(DeviceShiftsRequest $request, ShiftUploads $uploads): JsonResponse
    {
        return $this->answer($uploads->upload(DevicePlace::of($request->user()), $request->validated('shifts')));
    }

    public function meterResets(DeviceMeterResetsRequest $request, MeterResets $resets, Authority $authority, Stations $stations): JsonResponse
    {
        $place = DevicePlace::of($request->user());
        $results = [];

        foreach ($request->validated('resets') as $data) {
            try {
                $stations->at($place->location->id) ?? throw new Rejection('not_a_station');
                $nozzle = Nozzle::query()->whereKey($data['nozzle_id'])->where('station_id', $stations->at($place->location->id)->id)->whereNull('archived_at')->first() ?? throw new Rejection('nozzle_unknown', 'nozzle_id');
                $by = $authority->user($data['requested_by_id'], 'requested_by_id');

                if (! $authority->can($by, 'fuel.meter.request_reset', $place->scope())) {
                    throw new Rejection('not_permitted', 'requested_by_id');
                }

                $reset = $resets->request($nozzle, $data, $by, $authority->proven($place->device, $by, $data['actor_proof'] ?? null) !== null);
                $results[] = ['id' => $reset->id, 'status' => 'stored', 'reset_status' => $reset->status];
            } catch (Rejection $rejection) {
                $results[] = ['id' => $data['id'], 'status' => 'rejected', 'error' => $rejection->toArray()];
            } catch (ApiException $e) {
                $results[] = ['id' => $data['id'], 'status' => 'rejected', 'error' => (new Rejection($e->errorCode, array_key_first($e->errors) ?: null))->toArray()];
            }
        }

        return $this->answer($results);
    }

    /** @param list<array<string, mixed>> $results */
    private function answer(array $results): JsonResponse
    {
        if (collect($results)->every(fn (array $r) => $r['status'] === 'rejected')) {
            return response()->json(['message' => __('fuel.errors.upload_rejected'), 'code' => 'upload_rejected', 'results' => $results], 422);
        }

        return response()->json(['results' => $results]);
    }
}
