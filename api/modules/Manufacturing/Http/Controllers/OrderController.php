<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Manufacturing\Http\Lists\OrderList;
use Modules\Manufacturing\Http\Requests\CancelOrderRequest;
use Modules\Manufacturing\Http\Requests\CloseOrderRequest;
use Modules\Manufacturing\Http\Requests\IssueMaterialsRequest;
use Modules\Manufacturing\Http\Requests\ListOrdersRequest;
use Modules\Manufacturing\Http\Requests\PrintOrderRequest;
use Modules\Manufacturing\Http\Requests\ReleaseOrderRequest;
use Modules\Manufacturing\Http\Requests\ReportProductionRequest;
use Modules\Manufacturing\Http\Requests\ReturnMaterialsRequest;
use Modules\Manufacturing\Http\Requests\ShowOrderRequest;
use Modules\Manufacturing\Http\Requests\StoreOrderRequest;
use Modules\Manufacturing\Http\Requests\UpdateOrderRequest;
use Modules\Manufacturing\Http\Resources\OrderResource;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Orders\Materials;
use Modules\Manufacturing\Orders\Orders;
use Modules\Manufacturing\Orders\Reports;
use Modules\Manufacturing\Prints\OrderPrints;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** MFG-10 to MFG-16, MFG-24: production orders at the reader's locations (RBAC-04). */
class OrderController
{
    public function __construct(private readonly Orders $orders) {}

    public function index(ListOrdersRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Order::query()->with(OrderList::RELATIONS);
        $request->applyFilters($query, dateColumn: 'created_at');
        $request->applyTextSearch($query, ['number', 'note']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return OrderResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->plan($request->validated(), $request->user());

        return $this->detail($order)->response()->setStatusCode(201);
    }

    public function show(ShowOrderRequest $request, Order $mfgOrder): OrderResource
    {
        return $this->detail($mfgOrder);
    }

    public function update(UpdateOrderRequest $request, Order $mfgOrder): OrderResource
    {
        return $this->detail($this->orders->update($mfgOrder, $request->validated(), $request->user()));
    }

    public function release(ReleaseOrderRequest $request, Order $mfgOrder): OrderResource
    {
        return $this->detail($this->orders->release($mfgOrder, $request->user()));
    }

    public function cancel(CancelOrderRequest $request, Order $mfgOrder): OrderResource
    {
        return $this->detail($this->orders->cancel($mfgOrder, $request->user(), (string) $request->validated('reason')));
    }

    public function close(CloseOrderRequest $request, Order $mfgOrder): OrderResource
    {
        return $this->detail($this->orders->close($mfgOrder, $request->user(), $request->validated('note')));
    }

    public function issue(IssueMaterialsRequest $request, Order $mfgOrder, Materials $materials): JsonResponse
    {
        $materials->issue($mfgOrder, $request->validated(), $request->user());

        return $this->detail($mfgOrder)->response()->setStatusCode(201);
    }

    public function returnMaterials(ReturnMaterialsRequest $request, Order $mfgOrder, Materials $materials): JsonResponse
    {
        $materials->return($mfgOrder, $request->validated(), $request->user());

        return $this->detail($mfgOrder)->response()->setStatusCode(201);
    }

    public function report(ReportProductionRequest $request, Order $mfgOrder, Reports $reports): JsonResponse
    {
        $reports->report($mfgOrder, $request->validated(), $request->user());

        return $this->detail($mfgOrder)->response()->setStatusCode(201);
    }

    /** MFG-24: the production order or pick list, as HTML to print or a PDF. */
    public function printDocument(PrintOrderRequest $request, Order $mfgOrder, OrderPrints $prints, DocumentOutput $output): Response
    {
        $document = $prints->document($mfgOrder->load('planner'), $request->documentType());

        if ($request->wantsPdf()) {
            return response($output->pdf($document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
            ]);
        }

        return response($output->html($document), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function detail(Order $order): OrderResource
    {
        return OrderResource::make(Order::query()->with([...OrderList::RELATIONS, 'releaser', 'closer'])->findOrFail($order->id))->detail();
    }
}
