<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Modules\POS\Sync\DevicePlace;
use Modules\VanSales\Http\Requests\ConfirmLoadRequest;
use Modules\VanSales\Http\Requests\DeviceSyncRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Loads\Loads;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Sync\DeviceUploads;

/**
 * VAN-27, VAN-08: what a paired van device sends (device token only; place
 * from the device). A batch answers one result per record: 200 when
 * anything is stored, 422 `upload_rejected` when nothing is.
 */
class DeviceController
{
    public function sync(DeviceSyncRequest $request, DeviceUploads $uploads): JsonResponse
    {
        $results = $uploads->upload(DevicePlace::of($request->user()), $request->validated());
        $all = collect($results)->flatten(1);

        if ($all->every(fn (array $r) => $r['status'] === 'rejected')) {
            return response()->json(['message' => __('van_sales.errors.upload_rejected'), 'code' => 'upload_rejected', 'results' => $results], 422);
        }

        return response()->json(['results' => $results]);
    }

    public function confirmLoad(ConfirmLoadRequest $request, Load $vsLoad, Loads $loads, Views $views, ScopeResolver $resolver): JsonResponse
    {
        $place = DevicePlace::of($request->user());
        $van = Van::query()->whereNull('archived_at')->where('location_id', $place->location->id)->first();

        if ($van === null || $vsLoad->van_id !== $van->id) {
            throw Errors::make(404, 'load_unknown');
        }

        $person = User::query()->find($request->validated('user_id'));

        if ($person === null || ! $person->isActive()) {
            throw Errors::make(422, 'rep_unknown', field: 'user_id');
        }

        if (! $resolver->can($person, 'van_sales.load.confirm', $place->scope())) {
            throw Errors::make(403, 'not_permitted', field: 'user_id');
        }

        $load = $loads->confirm($vsLoad, $person, $request->validated('lines'));

        return response()->json(['data' => $views->loads(collect([$load]))[0]]);
    }
}
