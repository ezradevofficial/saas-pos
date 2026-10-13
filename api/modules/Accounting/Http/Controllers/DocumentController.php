<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\Exports\ListExport;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Documents\DocumentPrints;
use Modules\Accounting\Documents\Documents;
use Modules\Accounting\Http\Lists\DocumentList;
use Modules\Accounting\Http\Requests\AllocateDocumentRequest;
use Modules\Accounting\Http\Requests\CancelDocumentRequest;
use Modules\Accounting\Http\Requests\ListDocumentsRequest;
use Modules\Accounting\Http\Requests\PostDocumentRequest;
use Modules\Accounting\Http\Requests\ShowDocumentRequest;
use Modules\Accounting\Http\Requests\StoreDocumentRequest;
use Modules\Accounting\Http\Requests\UpdateDocumentRequest;
use Modules\Accounting\Http\Resources\DocumentResource;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Payments\Allocations;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ACC-09, ACC-10, ACC-19: customer and supplier invoices and credit notes. */
class DocumentController
{
    public function __construct(private readonly Documents $documents) {}

    public function index(ListDocumentsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->filter(Document::query()->with(DocumentList::RELATIONS), 'issue_date');
        $status = $request->status();

        if ($status === 'open') {
            $query->where('status', Document::POSTED)->where('open_minor', '>', 0);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $kind = (string) $request->validated('kind', '');

        if (in_array($kind, ['customer', 'supplier'], true)) {
            $query->where('kind', 'like', $kind.'_%');
        } elseif ($kind !== '') {
            $query->where('kind', $kind);
        }

        if ($request->filled('party')) {
            $query->where('party_id', $request->validated('party'));
        }

        $request->applySearch($query, ['number' => 'number', 'supplier_reference' => 'supplier_reference', 'memo' => 'memo']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return DocumentResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreDocumentRequest $request, Company $company): JsonResponse
    {
        return DocumentResource::make($this->documents->create($company, $request->validated(), $request->user()))->detail()->response()->setStatusCode(201);
    }

    public function show(ShowDocumentRequest $request, Document $accDocument): DocumentResource
    {
        return DocumentResource::make($accDocument)->detail();
    }

    public function update(UpdateDocumentRequest $request, Document $accDocument): DocumentResource
    {
        return DocumentResource::make($this->documents->update($accDocument, $request->validated()))->detail();
    }

    public function post(PostDocumentRequest $request, Document $accDocument): DocumentResource
    {
        return DocumentResource::make($this->documents->post($accDocument, $request->user()))->detail();
    }

    public function cancel(CancelDocumentRequest $request, Document $accDocument): DocumentResource
    {
        return DocumentResource::make($this->documents->cancel($accDocument))->detail();
    }

    /** ACC-10: a posted credit note settles open invoices of its party. */
    public function allocate(AllocateDocumentRequest $request, Document $accDocument, Allocations $allocations, CurrencyDecimals $decimals): DocumentResource
    {
        if (! $accDocument->isCredit()) {
            throw LedgerError::make('allocation_mismatch', field: 'allocations');
        }

        $date = CarbonImmutable::parse($request->validated('date', CarbonImmutable::now($accDocument->company->timezone ?: 'UTC')->toDateString()));

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $accDocument, $allocations, $decimals, $date) {
            foreach ($request->validated('allocations') as $index => $line) {
                $invoice = Document::query()->where('company_id', $accDocument->company_id)->findOrFail($line['document_id']);
                $allocations->allocate($accDocument, $invoice, Money::parse($line['amount'], $invoice->currency, $decimals), $date, $request->user());
            }
        });

        return DocumentResource::make($accDocument->refresh())->detail();
    }

    /** TPL-04: the posted document as a PDF, printed with the template that applies. */
    public function pdf(ShowDocumentRequest $request, Document $accDocument, DocumentPrints $prints, DocumentOutput $output): Response
    {
        if ($accDocument->status !== Document::POSTED) {
            throw LedgerError::make('document_not_posted');
        }

        $document = $prints->document($accDocument);

        return response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
        ]);
    }
}
