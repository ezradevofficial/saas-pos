<?php

namespace Modules\POS\Http\Controllers\Device;

use Illuminate\Http\JsonResponse;
use Modules\POS\Fiscal\SaleFiscalStatus;
use Modules\POS\Http\Requests\Device\ShowSaleFiscalRequest;
use Modules\POS\Models\Sale;

/** POS-10: a sale's fiscal state for the till's receipt (SaleFiscalStatus); the till shows "pending" until accepted. */
class SaleFiscalController
{
    public function __invoke(ShowSaleFiscalRequest $request, Sale $posSale, SaleFiscalStatus $status): JsonResponse
    {
        return response()->json(['data' => $status->of($posSale)]);
    }
}
