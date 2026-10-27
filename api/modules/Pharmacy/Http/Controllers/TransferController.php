<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Pharmacy\Http\Requests\StoreTransferRequest;
use Modules\Pharmacy\Stock\Transfers;

/** PHA-13: lots moved between two locations of one company. */
class TransferController
{
    public function __construct(private readonly Transfers $transfers) {}

    public function store(StoreTransferRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->transfers->move($request->validated(), $request->user())], 201);
    }
}
