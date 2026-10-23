<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Http\Requests\ReturnActionRequest;
use Modules\Ecommerce\Http\Requests\ReturnRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Orders\ReturnRefunds;
use Modules\Ecommerce\Orders\Returns;

/**
 * ECOM-42, ECOM-43: partial returns and exchanges of a delivered order in
 * the back office (Returns, ReturnRefunds). Each answer is the whole order
 * (Present::order), its returns included. Permissions at the order's
 * location through ReturnPolicy (the Form Requests).
 */
class ReturnController
{
    public function __construct(private readonly Returns $returns, private readonly ReturnRefunds $refunds) {}

    /** What the return would refund or collect (nothing is stored). */
    public function quote(ReturnRequest $request, Order $ecomOrder): JsonResponse
    {
        return response()->json(['data' => Returns::presentQuote($this->returns->quote($ecomOrder, $request->validated()))]);
    }

    public function store(ReturnRequest $request, Order $ecomOrder): JsonResponse
    {
        $return = $this->returns->create($ecomOrder, $request->user(), $request->validated());

        // Review H1: a refund above the user's limit (with the order's others) is recorded and waits for approval (202).
        return response()->json(['data' => Present::order($ecomOrder->refresh(), $request->user())], $return->approval_status === 'pending' ? 202 : 201);
    }

    public function refund(ReturnActionRequest $request, OrderReturn $ecomReturn): JsonResponse
    {
        $return = $this->refunds->refund($ecomReturn, $request->user(), $request->validated('reference'));

        // Review H1: above the user's limit the refund waits for approval (202).
        return response()->json(['data' => Present::order(Order::query()->findOrFail($ecomReturn->order_id), $request->user())], $return->approval_status === 'pending' ? 202 : 200);
    }

    public function collect(ReturnActionRequest $request, OrderReturn $ecomReturn): JsonResponse
    {
        $this->returns->collect($ecomReturn, $request->user(), (string) $request->validated('payment_method_id'), $request->validated('reference'));

        return response()->json(['data' => Present::order(Order::query()->findOrFail($ecomReturn->order_id), $request->user())]);
    }
}
