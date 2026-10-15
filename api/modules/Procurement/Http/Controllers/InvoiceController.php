<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Documents\Invoices;
use Modules\Procurement\Http\Lists\InvoiceList;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListInvoicesRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreInvoiceRequest;
use Modules\Procurement\Http\Requests\UpdateInvoiceRequest;
use Modules\Procurement\Http\Resources\InvoiceResource;
use Modules\Procurement\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-15: supplier invoices, their three-way match and approval. */
class InvoiceController
{
    public const DETAIL = [...InvoiceList::RELATIONS, 'lines.orderLine', 'lines.taxCode'];

    public function __construct(private readonly Invoices $invoices) {}

    public function index(ListInvoicesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Invoice::query()->with(InvoiceList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return InvoiceResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Invoice $procurementInvoice): InvoiceResource
    {
        return $this->detail($procurementInvoice);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        return $this->detail($this->invoices->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $procurementInvoice): InvoiceResource
    {
        return $this->detail($this->invoices->update($procurementInvoice, $request->validated()));
    }

    public function approve(DocumentActionRequest $request, Invoice $procurementInvoice): InvoiceResource
    {
        return $this->detail($this->invoices->approve($procurementInvoice, $request->user(), $request->mayAt('override'), $request->validated('override_reason')));
    }

    public function cancel(DocumentActionRequest $request, Invoice $procurementInvoice): InvoiceResource
    {
        return $this->detail($this->invoices->cancel($procurementInvoice, $request->user(), (string) $request->validated('reason')));
    }

    private function detail(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($invoice->refresh()->load(self::DETAIL))->detail();
    }
}
