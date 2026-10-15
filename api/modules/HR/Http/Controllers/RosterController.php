<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Http\Lists\RosterShiftList;
use Modules\HR\Http\Requests\ListRosterShiftsRequest;
use Modules\HR\Http\Requests\ListRostersRequest;
use Modules\HR\Http\Requests\ListRotationsRequest;
use Modules\HR\Http\Requests\ListShiftsRequest;
use Modules\HR\Http\Requests\ListSwapsRequest;
use Modules\HR\Http\Requests\RosterRequest;
use Modules\HR\Http\Requests\RosterShiftRequest;
use Modules\HR\Http\Requests\RotationRequest;
use Modules\HR\Http\Requests\ShiftRequest;
use Modules\HR\Http\Requests\StoreRosterRequest;
use Modules\HR\Http\Requests\StoreRosterShiftRequest;
use Modules\HR\Http\Requests\StoreRotationRequest;
use Modules\HR\Http\Requests\StoreShiftRequest;
use Modules\HR\Http\Requests\StoreSwapRequest;
use Modules\HR\Http\Requests\SwapRequest;
use Modules\HR\Http\Resources\RosterShiftResource;
use Modules\HR\Http\Resources\SetupResource;
use Modules\HR\Http\Resources\ShiftSwapResource;
use Modules\HR\Models\Roster;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\Rotation;
use Modules\HR\Models\Shift;
use Modules\HR\Models\ShiftSwap;
use Modules\HR\Rosters\RosterGenerator;
use Modules\HR\Rosters\RosterSetup;
use Modules\HR\Rosters\RosterShifts;
use Modules\HR\Rosters\ShiftSwaps;
use Modules\HR\Support\Errors;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-16 to HR-19: shifts, rotations, rosters, the roster grid and shift swaps. */
class RosterController
{
    public const SWAP_RELATIONS = ['employee', 'peer', 'rosterShift.employee', 'rosterShift.shift', 'peerRosterShift.employee', 'peerRosterShift.shift'];

    public function __construct(
        private readonly RosterSetup $setup,
        private readonly RosterShifts $planned,
        private readonly ShiftSwaps $swaps,
    ) {}

    public function shifts(ListShiftsRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(Shift::query(), ['code', 'name'])->orderBy('code')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeShift(StoreShiftRequest $request): JsonResponse
    {
        return SetupResource::make($this->setup->createShift($request->validated())->refresh())->response()->setStatusCode(201);
    }

    public function updateShift(ShiftRequest $request, Shift $hrShift): SetupResource
    {
        return SetupResource::make($this->setup->updateShift($hrShift, $request->validated()));
    }

    public function archiveShift(ShiftRequest $request, Shift $hrShift): SetupResource
    {
        $hrShift->isArchived() || $hrShift->archive();

        return SetupResource::make($hrShift->refresh());
    }

    public function rotations(ListRotationsRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(Rotation::query(), ['name'])->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeRotation(StoreRotationRequest $request): JsonResponse
    {
        return SetupResource::make($this->setup->createRotation($request->validated())->refresh())->response()->setStatusCode(201);
    }

    public function updateRotation(RotationRequest $request, Rotation $hrRotation): SetupResource
    {
        return SetupResource::make($this->setup->updateRotation($hrRotation, $request->validated()));
    }

    public function archiveRotation(RotationRequest $request, Rotation $hrRotation): SetupResource
    {
        $hrRotation->isArchived() || $hrRotation->archive();

        return SetupResource::make($hrRotation->refresh());
    }

    public function rosters(ListRostersRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(Roster::query()->with(['branch', 'department']), ['name'])->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeRoster(StoreRosterRequest $request): JsonResponse
    {
        return SetupResource::make($this->setup->createRoster($request->validated(), $request->user())->load(['branch', 'department', 'members.employee']))->response()->setStatusCode(201);
    }

    public function showRoster(RosterRequest $request, Roster $hrRoster): SetupResource
    {
        return SetupResource::make($hrRoster->load(['branch', 'department', 'members.employee']));
    }

    public function updateRoster(RosterRequest $request, Roster $hrRoster): SetupResource
    {
        return SetupResource::make($this->setup->updateRoster($hrRoster, $request->validated())->load(['branch', 'department', 'members.employee']));
    }

    public function members(RosterRequest $request, Roster $hrRoster): SetupResource
    {
        $this->setup->putMembers($hrRoster, $request->validated('members'), $request->user());

        return SetupResource::make($hrRoster->refresh()->load(['branch', 'department', 'members.employee']));
    }

    public function generate(RosterRequest $request, Roster $hrRoster, RosterGenerator $generator): JsonResponse
    {
        if ($hrRoster->isArchived()) {
            throw Errors::make(409, 'document_not_open');
        }

        return new JsonResponse(['data' => $generator->generate($hrRoster, CarbonImmutable::parse($request->validated('from')), CarbonImmutable::parse($request->validated('to')), $request->user())]);
    }

    public function archiveRoster(RosterRequest $request, Roster $hrRoster): SetupResource
    {
        $hrRoster->isArchived() || $hrRoster->archive();

        return SetupResource::make($hrRoster->refresh()->load(['branch', 'department']));
    }

    public function plannedShifts(ListRosterShiftsRequest $request, ListExport $export, BusinessCalendar $calendar): AnonymousResourceCollection|StreamedResponse
    {
        $from = CarbonImmutable::parse($request->validated('from', CarbonImmutable::now()->startOfWeek(CarbonInterface::MONDAY)->toDateString()));
        $to = CarbonImmutable::parse($request->validated('to', $from->addDays(6)->toDateString()));

        if ($to->lessThan($from) || $from->diffInDays($to) > 31) {
            throw Errors::make(422, 'range_too_long', ['days' => 31], 'to');
        }

        $query = RosterShift::query()->with(RosterShiftList::RELATIONS)->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $request->applyFilters($query, defaultStatus: 'planned');
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        $shifts = $query->get();
        $countries = Company::query()->whereKey($shifts->pluck('company_id')->unique()->all())->pluck('country', 'id');
        $shifts->each(fn (RosterShift $shift) => $shift->setAttribute('holiday', $calendar->isHoliday($shift->date, $countries[$shift->company_id] ?? null)));

        return RosterShiftResource::collection($shifts)->additional(['meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }

    public function storePlanned(StoreRosterShiftRequest $request): JsonResponse
    {
        return RosterShiftResource::make($this->planned->create($request->validated(), $request->user())->load(RosterShiftList::RELATIONS))->response()->setStatusCode(201);
    }

    public function updatePlanned(RosterShiftRequest $request, RosterShift $hrRosterShift): RosterShiftResource
    {
        return RosterShiftResource::make($this->planned->update($hrRosterShift, $request->validated())->load(RosterShiftList::RELATIONS));
    }

    public function cancelPlanned(RosterShiftRequest $request, RosterShift $hrRosterShift): RosterShiftResource
    {
        return RosterShiftResource::make($this->planned->cancel($hrRosterShift)->load(RosterShiftList::RELATIONS));
    }

    public function swaps(ListSwapsRequest $request): AnonymousResourceCollection
    {
        $query = ShiftSwap::query()->with(self::SWAP_RELATIONS);
        $request->applyFilters($query);

        return ShiftSwapResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeSwap(StoreSwapRequest $request): JsonResponse
    {
        return ShiftSwapResource::make($this->swaps->create($request->bodyEmployee(), $request->safe()->except('employee_id'), $request->user())->load(self::SWAP_RELATIONS))->response()->setStatusCode(201);
    }

    public function showSwap(SwapRequest $request, ShiftSwap $hrShiftSwap): ShiftSwapResource
    {
        return ShiftSwapResource::make($hrShiftSwap->load(self::SWAP_RELATIONS));
    }

    public function acceptSwap(SwapRequest $request, ShiftSwap $hrShiftSwap): ShiftSwapResource
    {
        return ShiftSwapResource::make($this->swaps->accept($hrShiftSwap, $request->user())->load(self::SWAP_RELATIONS));
    }

    public function declineSwap(SwapRequest $request, ShiftSwap $hrShiftSwap): ShiftSwapResource
    {
        return ShiftSwapResource::make($this->swaps->decline($hrShiftSwap, $request->user())->load(self::SWAP_RELATIONS));
    }

    public function cancelSwap(SwapRequest $request, ShiftSwap $hrShiftSwap): ShiftSwapResource
    {
        return ShiftSwapResource::make($this->swaps->cancel($hrShiftSwap)->load(self::SWAP_RELATIONS));
    }
}
