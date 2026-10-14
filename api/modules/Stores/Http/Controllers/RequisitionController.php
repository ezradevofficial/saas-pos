<?php

namespace Modules\Stores\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Stores\Documents\Issues;
use Modules\Stores\Documents\Requisitions;
use Modules\Stores\Http\Lists\RequisitionList;
use Modules\Stores\Http\Requests\DocumentActionRequest;
use Modules\Stores\Http\Requests\IssueRequest;
use Modules\Stores\Http\Requests\ListRequisitionsRequest;
use Modules\Stores\Http\Requests\ShowDocumentRequest;
use Modules\Stores\Http\Requests\StoreRequisitionRequest;
use Modules\Stores\Http\Resources\IssueResource;
use Modules\Stores\Http\Resources\RequisitionResource;
use Modules\Stores\Models\Requisition;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** STR-02 to STR-04: requisitions at the reader's stores (RBAC-04), and issuing against them. */
class RequisitionController
{
    public const DETAIL = [...RequisitionList::RELATIONS, 'lines.item', 'lines.uom', 'issues'];

    public function __construct(
        private readonly Requisitions $requisitions,
        private readonly Issues $issues,
    ) {}

    public function index(ListRequisitionsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Requisition::query()->with(RequisitionList::RELATIONS)->withCount('lines');
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

        return RequisitionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Requisition $storesRequisition): RequisitionResource
    {
        return RequisitionResource::make($storesRequisition->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function store(StoreRequisitionRequest $request): JsonResponse
    {
        $requisition = $this->requisitions->create($request->validated(), $request->user());

        return RequisitionResource::make($requisition->refresh()->load(self::DETAIL)->loadCount('lines'))->detail()->response()->setStatusCode(201);
    }

    public function cancel(DocumentActionRequest $request, Requisition $storesRequisition): RequisitionResource
    {
        $requisition = $this->requisitions->cancel($storesRequisition, $request->user(), (string) $request->validated('reason'));

        return RequisitionResource::make($requisition->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function close(DocumentActionRequest $request, Requisition $storesRequisition): RequisitionResource
    {
        $requisition = $this->requisitions->close($storesRequisition, $request->user(), (string) $request->validated('reason'));

        return RequisitionResource::make($requisition->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function issue(IssueRequest $request, Requisition $storesRequisition): JsonResponse
    {
        $issue = $this->issues->issue($storesRequisition, $request->validated(), $request->user());

        return IssueResource::make($issue->refresh()->load(IssueController::DETAIL)->loadCount('lines'))->detail()->response()->setStatusCode(201);
    }
}
