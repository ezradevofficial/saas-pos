<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Tenancy\DevicePairing;
use App\Core\Tenancy\Http\Requests\PairDeviceRequest;
use App\Core\Tenancy\Http\Resources\DeviceResource;
use App\Core\Tenancy\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** TEN-05: the device side of pairing. */
class DevicePairingController
{
    public function __construct(private readonly DevicePairing $pairing) {}

    /** Public and rate-limited: the one-time code is the credential. */
    public function pair(PairDeviceRequest $request): JsonResponse
    {
        $paired = $this->pairing->pair(
            $request->validated('code'),
            $request->validated('device_name'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'token' => $paired['token'],
            'device' => DeviceResource::make($paired['device'])->resolve($request),
        ]);
    }

    /** The signed-in device and where it sits. */
    public function me(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $location = $device->location()->with('branch.company')->firstOrFail();

        return response()->json([
            'data' => array_merge(DeviceResource::make($device)->resolve($request), [
                'location' => ['id' => $location->id, 'name' => $location->name, 'type' => $location->type],
                'branch' => ['id' => $location->branch->id, 'name' => $location->branch->name],
                'company' => ['id' => $location->branch->company->id, 'name' => $location->branch->company->name],
            ]),
        ]);
    }
}
