<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\DevicePairing;
use App\Core\Tenancy\Http\Requests\DeviceActionRequest;
use App\Core\Tenancy\Http\Requests\DeviceListRequest;
use App\Core\Tenancy\Http\Requests\StoreDeviceRequest;
use App\Core\Tenancy\Http\Requests\UpdateDeviceRequest;
use App\Core\Tenancy\Http\Resources\DeviceResource;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** TEN-05: devices of a location, their pairing codes, suspension, resumption and unpairing. */
class DeviceController
{
    use ChecksScope;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Visibility $visibility,
        private readonly DevicePairing $pairing,
        private readonly Archiver $archiver,
    ) {}

    public function index(DeviceListRequest $request, Location $location): AnonymousResourceCollection
    {
        abort_unless($this->visibility->reaches($request->user(), 'core.device.view', $location), 404);

        $query = $this->resolver->visibleIds($request->user(), 'core.device.view')
            ->applyTo($location->devices()->getQuery()->with('currentSecret'), Scope::LOCATION);

        if ($request->has('status')) {
            $query->where('status', $request->validated('status'));
        }

        return DeviceResource::collection(
            $query->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StoreDeviceRequest $request, Location $location): DeviceResource
    {
        // TEN-06: nothing new under an archived location.
        return DeviceResource::make(DB::transaction(
            fn () => $this->archiver->lockActive(Location::class, $location->id)->devices()->create($request->validated()),
        ));
    }

    public function show(Request $request, Device $device): DeviceResource
    {
        $this->visibleOr404($request, $device);

        return DeviceResource::make($device->load('currentSecret'));
    }

    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        $device->fill($request->validated())->save();

        return DeviceResource::make($device);
    }

    /** The code is shown once; only its hash is stored. */
    public function pairingCode(DeviceActionRequest $request, Device $device): JsonResponse
    {
        $this->authorizeInScope($request, 'pair', $device);
        $issued = DB::transaction(function () use ($device) {
            $this->archiver->lockActive(Location::class, $device->location_id);

            return $this->pairing->issueCode($device);
        });

        return response()->json([
            'code' => $issued['code'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'device' => DeviceResource::make($device)->resolve($request),
        ]);
    }

    public function suspend(DeviceActionRequest $request, Device $device): DeviceResource
    {
        $this->authorizeInScope($request, 'suspend', $device);

        return DeviceResource::make($this->pairing->suspend($device));
    }

    public function resume(DeviceActionRequest $request, Device $device): DeviceResource
    {
        $this->authorizeInScope($request, 'suspend', $device);

        return DeviceResource::make($this->pairing->resume($device));
    }

    public function unpair(DeviceActionRequest $request, Device $device): DeviceResource
    {
        $this->authorizeInScope($request, 'pair', $device);

        return DeviceResource::make($this->pairing->unpair($device));
    }
}
