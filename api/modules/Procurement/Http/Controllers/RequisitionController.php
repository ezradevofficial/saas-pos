<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Documents\Requisitions;
use Modules\Procurement\Http\Lists\RequisitionList;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListRequisitionsRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreRequisitionRequest;
use Modules\Procurement\Http\Requests\UpdateRequisitionRequest;
use Modules\Procurement\Http\Resources\RequisitionResource;
use Modules\Procurement\Models\Requisition;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-02: purchase requisitions in the back office, at the reader's places (RBAC-04). */
class RequisitionController
{
    public const DETAIL = [...RequisitionList::RELATIONS, 'lines.item', 'lines.uom', 'lines.costCentre', 'lines.project'];

    public function __construct(private readonly Requisitions $requisitions) {}

    public function index(ListRequisitionsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Requisition::query()->with(RequisitionList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RequisitionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Requisition $procurementRequisition): RequisitionResource
    {
        return $this->detail($procurementRequisition);
    }

    public function store(StoreRequisitionRequest $request): JsonResponse
    {
        return $this->detail($this->requisitions->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateRequisitionRequest $request, Requisition $procurementRequisition): RequisitionResource
    {
        return $this->detail($this->requisitions->update($procurementRequisition, $request->validated()));
    }

    public function submit(DocumentActionRequest $request, Requisition $procurementRequisition): RequisitionResource
    {
        return $this->detail($this->requisitions->submit($procurementRequisition, $request->user()));
    }

    public function cancel(DocumentActionRequest $request, Requisition $procurementRequisition): RequisitionResource
    {
        return $this->detail($this->requisitions->cancel($procurementRequisition, $request->user(), (string) $request->validated('reason')));
    }

    private function detail(Requisition $requisition): RequisitionResource
    {
        return RequisitionResource::make($requisition->refresh()->load(self::DETAIL))->detail();
    }
}
