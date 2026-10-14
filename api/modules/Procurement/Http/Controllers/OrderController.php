<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\DocumentTemplates\DocumentEmails;
use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Procurement\Documents\Orders;
use Modules\Procurement\Http\Lists\OrderList;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListOrdersRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreOrderRequest;
use Modules\Procurement\Http\Requests\UpdateOrderRequest;
use Modules\Procurement\Http\Resources\OrderResource;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Printing\ProcurementPrints;
use Modules\Procurement\Support\ProcurementError;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-13: purchase orders, their flow, printing and sending. */
class OrderController
{
    public const DETAIL = [...OrderList::RELATIONS, 'lines.item', 'lines.uom', 'lines.taxCode', 'lines.costCentre', 'lines.project'];

    public function __construct(private readonly Orders $orders) {}

    public function index(ListOrdersRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Order::query()->with(OrderList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return OrderResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Order $procurementOrder): OrderResource
    {
        return $this->detail($procurementOrder);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        return $this->detail($this->orders->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateOrderRequest $request, Order $procurementOrder): OrderResource
    {
        return $this->detail($this->orders->update($procurementOrder, $request->validated()));
    }

    public function submit(DocumentActionRequest $request, Order $procurementOrder): OrderResource
    {
        return $this->detail($this->orders->submit($procurementOrder, $request->user()));
    }

    public function cancel(DocumentActionRequest $request, Order $procurementOrder): OrderResource
    {
        return $this->detail($this->orders->cancel($procurementOrder, $request->user(), (string) $request->validated('reason')));
    }

    /** PRC-13, TPL-04: mark the PO sent, emailing its PDF when an address is given (or the supplier has one). */
    public function send(DocumentActionRequest $request, Order $procurementOrder, ProcurementPrints $prints, DocumentEmails $emails): OrderResource
    {
        $order = $this->orders->markSent($procurementOrder);
        $document = $prints->order($order);
        $email = $request->validated('email') ?: $document->email;

        if ($email !== null) {
            $emails->send($document, $order, (string) $email, (string) $request->validated('locale', app()->getLocale()), $request->user());
        }

        return $this->detail($order);
    }

    /** TPL-01: the PO printed from its template (drafts print without a number). */
    public function pdf(DocumentActionRequest $request, Order $procurementOrder, ProcurementPrints $prints, DocumentOutput $output): Response
    {
        if ($procurementOrder->status === Order::CANCELLED) {
            throw ProcurementError::make('document_not_open', status: 409);
        }

        $document = $prints->order($procurementOrder);

        return response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
        ]);
    }

    private function detail(Order $order): OrderResource
    {
        return OrderResource::make($order->refresh()->load(self::DETAIL))->detail();
    }
}
