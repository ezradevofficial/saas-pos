<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Documents\Receipts;
use Modules\Procurement\Http\Lists\ReceiptList;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListReceiptsRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreReceiptRequest;
use Modules\Procurement\Http\Requests\UpdateReceiptRequest;
use Modules\Procurement\Http\Resources\ReceiptResource;
use Modules\Procurement\Models\Receipt;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-14: goods received notes, at the reader's locations (RBAC-04). */
class ReceiptController
{
    public const DETAIL = [...ReceiptList::RELATIONS, 'lines.item', 'lines.uom', 'lines.orderLine'];

    public function __construct(private readonly Receipts $receipts) {}

    public function index(ListReceiptsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Receipt::query()->with(ReceiptList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ReceiptResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Receipt $procurementReceipt): ReceiptResource
    {
        return $this->detail($procurementReceipt);
    }

    public function store(StoreReceiptRequest $request): JsonResponse
    {
        return $this->detail($this->receipts->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateReceiptRequest $request, Receipt $procurementReceipt): ReceiptResource
    {
        return $this->detail($this->receipts->update($procurementReceipt, $request->validated()));
    }

    public function post(DocumentActionRequest $request, Receipt $procurementReceipt): ReceiptResource
    {
        return $this->detail($this->receipts->post($procurementReceipt, $request->user()));
    }

    public function cancel(DocumentActionRequest $request, Receipt $procurementReceipt): ReceiptResource
    {
        return $this->detail($this->receipts->cancel($procurementReceipt, $request->user(), (string) $request->validated('reason')));
    }

    private function detail(Receipt $receipt): ReceiptResource
    {
        return ReceiptResource::make($receipt->refresh()->load(self::DETAIL))->detail();
    }
}
