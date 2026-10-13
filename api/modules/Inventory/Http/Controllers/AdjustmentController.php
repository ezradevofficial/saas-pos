<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Inventory\Documents\Adjustments;
use Modules\Inventory\Http\Lists\AdjustmentList;
use Modules\Inventory\Http\Requests\DocumentActionRequest;
use Modules\Inventory\Http\Requests\ListAdjustmentsRequest;
use Modules\Inventory\Http\Requests\ShowDocumentRequest;
use Modules\Inventory\Http\Requests\StoreAdjustmentRequest;
use Modules\Inventory\Http\Resources\AdjustmentResource;
use Modules\Inventory\Models\Adjustment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** INV-10: adjustments in the back office, at the reader's locations (RBAC-04). */
class AdjustmentController
{
    public const DETAIL = [...AdjustmentList::RELATIONS, 'lines.item', 'lines.uom', 'lines.batch'];

    public function __construct(private readonly Adjustments $adjustments) {}

    public function index(ListAdjustmentsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Adjustment::query()->with(AdjustmentList::RELATIONS)->withCount('lines');
        $request->applyFilters($query, dateColumn: 'created_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('note', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AdjustmentResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Adjustment $inventoryAdjustment): AdjustmentResource
    {
        return AdjustmentResource::make($inventoryAdjustment->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function store(StoreAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->create($request->validated(), $request->user());

        return AdjustmentResource::make($adjustment->refresh()->load(self::DETAIL)->loadCount('lines'))->detail()->response()->setStatusCode(201);
    }

    public function cancel(DocumentActionRequest $request, Adjustment $inventoryAdjustment): AdjustmentResource
    {
        $adjustment = $this->adjustments->cancel($inventoryAdjustment, $request->user(), (string) $request->validated('reason'));

        return AdjustmentResource::make($adjustment->load(self::DETAIL)->loadCount('lines'))->detail();
    }
}
