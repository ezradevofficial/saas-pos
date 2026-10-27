<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Pharmacy\Http\Requests\ShowReceiptRequest;
use Modules\Pharmacy\Http\Requests\StoreReceiptRequest;
use Modules\Pharmacy\Http\Resources\ReceiptResource;
use Modules\Pharmacy\Models\Receipt;
use Modules\Pharmacy\Stock\Receiving;

/** PHA-06: receiving lots in the back office. */
class ReceiptController
{
    public const DETAIL = ['company', 'branch', 'location', 'supplier', 'creator', 'lines.item', 'lines.lot', 'lines.uom'];

    public function __construct(private readonly Receiving $receiving) {}

    public function store(StoreReceiptRequest $request): JsonResponse
    {
        $receipt = $this->receiving->receive($request->validated(), $request->user());

        return ReceiptResource::make($receipt->load(self::DETAIL))->response()->setStatusCode(201);
    }

    public function show(ShowReceiptRequest $request, Receipt $pharmacyReceipt): ReceiptResource
    {
        return ReceiptResource::make($pharmacyReceipt->load(self::DETAIL));
    }
}
