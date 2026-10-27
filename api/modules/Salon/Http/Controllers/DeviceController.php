<?php

namespace Modules\Salon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\POS\Sync\DevicePlace;
use Modules\Salon\Http\Requests\DeviceSyncRequest;
use Modules\Salon\Sync\DeviceUploads;

/**
 * SAL-29: what a paired salon till sends (device token only; the place is the device's). A batch answers one result per
 * record: 200 when anything is stored, 422 `upload_rejected` when nothing is.
 */
class DeviceController
{
    public function sync(DeviceSyncRequest $request, DeviceUploads $uploads): JsonResponse
    {
        $results = $uploads->upload(DevicePlace::of($request->user()), $request->validated());
        $all = collect($results)->flatten(1);

        if ($all->every(fn (array $r) => $r['status'] === 'rejected')) {
            return response()->json(['message' => __('salon.errors.upload_rejected'), 'code' => 'upload_rejected', 'results' => $results], 422);
        }

        return response()->json(['results' => $results]);
    }
}
