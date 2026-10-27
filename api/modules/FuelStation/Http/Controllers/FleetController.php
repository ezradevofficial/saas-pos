<?php

namespace Modules\FuelStation\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Paged;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\ArchiveVehicleRequest;
use Modules\FuelStation\Http\Requests\ListVehiclesRequest;
use Modules\FuelStation\Http\Requests\ListVouchersRequest;
use Modules\FuelStation\Http\Requests\ShowVoucherRequest;
use Modules\FuelStation\Http\Requests\StoreVehicleRequest;
use Modules\FuelStation\Http\Requests\StoreVoucherRequest;
use Modules\FuelStation\Http\Requests\UpdateVehicleRequest;
use Modules\FuelStation\Http\Requests\VoidVoucherRequest;
use Modules\FuelStation\Models\Vehicle;
use Modules\FuelStation\Models\Voucher;
use Modules\FuelStation\Support\Access;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Vouchers\Vouchers;

/**
 * FUEL-20, FUEL-21: fleet vehicles (a customer's registrations) and prepaid
 * fuel vouchers. Vehicles are archived, never deleted; vouchers are voided
 * with a reason; every change is audited.
 */
class FleetController
{
    public function __construct(private readonly Access $access, private readonly Present $present, private readonly Vouchers $vouchers) {}

    public function vehicles(ListVehiclesRequest $request): JsonResponse
    {
        $query = $this->access->scopeCompanies(Vehicle::query(), $request->user(), 'fuel.vehicle.view')->orderBy('registration');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->validated('company_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('party_id', $request->validated('customer_id'));
        }

        if ($request->filled('search')) {
            $query->where('registration', 'like', '%'.addcslashes(Vouchers::normalise((string) $request->validated('search')), '\\%_').'%');
        }

        return Paged::respond($query, $request, fn (Vehicle $v) => $this->present->vehicle($v));
    }

    public function storeVehicle(StoreVehicleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['registration'] = Vouchers::normalise($data['registration']);

        try {
            $vehicle = Vehicle::create($data);
        } catch (UniqueConstraintViolationException) {
            throw FuelError::make(422, 'registration_used', field: 'registration');
        }

        return response()->json(['data' => $this->present->vehicle($vehicle->refresh())], 201);
    }

    public function updateVehicle(UpdateVehicleRequest $request, Vehicle $fuelVehicle): JsonResponse
    {
        $fuelVehicle->fill($request->validated())->save();

        return response()->json(['data' => $this->present->vehicle($fuelVehicle->refresh())]);
    }

    public function archiveVehicle(ArchiveVehicleRequest $request, Vehicle $fuelVehicle): JsonResponse
    {
        $fuelVehicle->archive();

        return response()->json(['data' => $this->present->vehicle($fuelVehicle->refresh())]);
    }

    public function vouchers(ListVouchersRequest $request): JsonResponse
    {
        $query = $this->access->scopeCompanies(Voucher::query(), $request->user(), 'fuel.voucher.view')->orderByDesc('created_at')->orderByDesc('id');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->validated('company_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('search')) {
            $query->whereRaw('upper(code) like ?', ['%'.addcslashes(Vouchers::normalise((string) $request->validated('search')), '\\%_').'%']);
        }

        return Paged::respond($query, $request, fn (Voucher $v) => $this->present->voucher($v));
    }

    public function showVoucher(ShowVoucherRequest $request, Voucher $fuelVoucher): JsonResponse
    {
        return response()->json(['data' => $this->present->voucher($fuelVoucher, true)]);
    }

    public function storeVoucher(StoreVoucherRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present->voucher($this->vouchers->issue($request->validated(), $request->user()))], 201);
    }

    public function voidVoucher(VoidVoucherRequest $request, Voucher $fuelVoucher): JsonResponse
    {
        return response()->json(['data' => $this->present->voucher($this->vouchers->void($fuelVoucher, $request->validated('reason'), $request->user()), true)]);
    }
}
