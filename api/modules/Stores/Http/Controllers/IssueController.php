<?php

namespace Modules\Stores\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Stores\Documents\Issues;
use Modules\Stores\Documents\Returns;
use Modules\Stores\Http\Lists\IssueList;
use Modules\Stores\Http\Requests\DocumentActionRequest;
use Modules\Stores\Http\Requests\ListIssuesRequest;
use Modules\Stores\Http\Requests\PrintIssueRequest;
use Modules\Stores\Http\Requests\ReturnRequest;
use Modules\Stores\Http\Requests\ShowDocumentRequest;
use Modules\Stores\Http\Resources\IssueResource;
use Modules\Stores\Http\Resources\ReturnResource;
use Modules\Stores\Http\Resources\Values;
use Modules\Stores\Models\Issue;
use Modules\Stores\Prints\IssuePrints;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** STR-04 to STR-06, STR-14: issues at the reader's stores (RBAC-04): acknowledge, return, print. */
class IssueController
{
    public const DETAIL = [...IssueList::RELATIONS, 'lines.item', 'lines.uom', 'returns'];

    public function __construct(
        private readonly Issues $issues,
        private readonly Returns $returns,
    ) {}

    public function index(ListIssuesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Issue::query()->with(IssueList::RELATIONS)->withCount('lines');
        $request->applyFilters($query, dateColumn: 'issued_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('note', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return IssueResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Issue $storesIssue): IssueResource
    {
        return IssueResource::make($storesIssue->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function acknowledge(DocumentActionRequest $request, Issue $storesIssue): IssueResource
    {
        $issue = $this->issues->acknowledge($storesIssue, $request->user(), $request->validated('note'));

        return IssueResource::make($issue->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function returnGoods(ReturnRequest $request, Issue $storesIssue): JsonResponse
    {
        $return = $this->returns->create($storesIssue, $request->validated(), $request->user());

        return ReturnResource::make($return->load(ReturnController::DETAIL)->loadCount('lines'))->detail()->response()->setStatusCode(201);
    }

    /** STR-14: the delivery note or issue voucher, as HTML to print or a PDF. */
    public function printDocument(PrintIssueRequest $request, Issue $storesIssue, IssuePrints $prints, DocumentOutput $output): Response
    {
        $document = $prints->document($storesIssue->load('issuer', 'acknowledger'), $request->documentType(), Values::visible($request, $storesIssue->location_id));

        if ($request->wantsPdf()) {
            return response($output->pdf($document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
            ]);
        }

        return response($output->html($document), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
