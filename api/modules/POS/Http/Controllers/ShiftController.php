<?php

namespace Modules\POS\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\POS\Http\Lists\ShiftList;
use Modules\POS\Http\Requests\ListShiftsRequest;
use Modules\POS\Http\Requests\ShowPosRecordRequest;
use Modules\POS\Http\Resources\ShiftResource;
use Modules\POS\Models\Shift;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** POS-04, POS-12: shifts and cash-up in the back office, scoped by location (RBAC-04). */
class ShiftController
{
    public function index(ListShiftsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(Shift::query()->with(ShiftList::RELATIONS)->withCount('sales'), 'opened_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q
                ->whereIn('device_id', Device::query()->where('name', 'ilike', $like)->select('id'))
                ->orWhereIn('opened_by', User::query()->where('name', 'ilike', $like)->select('id')));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ShiftResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowPosRecordRequest $request, Shift $posShift): ShiftResource
    {
        $posShift->load([...ShiftList::RELATIONS, 'cashMovements' => fn ($q) => $q->with(['user', 'approver'])->orderBy('occurred_at')])->loadCount('sales');
        $late = "flags @> '[{\"code\": \"received_after_close\"}]'::jsonb";
        $posShift->setAttribute('received_after_close', $posShift->sales()->whereRaw($late)->count() + $posShift->cashMovements()->whereRaw($late)->count());

        return ShiftResource::make($posShift)->detail();
    }
}
