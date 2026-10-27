<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pharmacy\Http\Lists\WriteOffList;
use Modules\Pharmacy\Http\Requests\CancelWriteOffRequest;
use Modules\Pharmacy\Http\Requests\ListWriteOffsRequest;
use Modules\Pharmacy\Http\Requests\ShowWriteOffRequest;
use Modules\Pharmacy\Http\Requests\StoreWriteOffRequest;
use Modules\Pharmacy\Http\Resources\WriteOffResource;
use Modules\Pharmacy\Models\WriteOff;
use Modules\Pharmacy\WriteOffs\WriteOffs;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PHA-12: write-offs in the back office, at the reader's locations (RBAC-04). */
class WriteOffController
{
    public const DETAIL = [...WriteOffList::RELATIONS, 'lines.item', 'lines.lot'];

    public function __construct(private readonly WriteOffs $writeOffs) {}

    public function index(ListWriteOffsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = WriteOff::query()->with(WriteOffList::RELATIONS)->withCount('lines');
        $request->applyFilters($query, 'location_id', 'created_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('note', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return WriteOffResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowWriteOffRequest $request, WriteOff $pharmacyWriteOff): WriteOffResource
    {
        return WriteOffResource::make($pharmacyWriteOff->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function store(StoreWriteOffRequest $request): JsonResponse
    {
        $writeOff = $this->writeOffs->create($request->validated(), $request->user());

        return WriteOffResource::make($writeOff->refresh()->load(self::DETAIL)->loadCount('lines'))->detail()->response()->setStatusCode(201);
    }

    public function cancel(CancelWriteOffRequest $request, WriteOff $pharmacyWriteOff): WriteOffResource
    {
        $writeOff = $this->writeOffs->cancel($pharmacyWriteOff, $request->user(), (string) $request->validated('reason'));

        return WriteOffResource::make($writeOff->load(self::DETAIL)->loadCount('lines'))->detail();
    }
}
