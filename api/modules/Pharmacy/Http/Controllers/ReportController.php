<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pharmacy\Http\Requests\ListExpiryReportRequest;
use Modules\Pharmacy\Http\Requests\ListSlowMoversRequest;
use Modules\Pharmacy\Http\Resources\StockResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PHA-24: the expiry and slow-mover reports (the register and the prescription log are lists of their own). */
class ReportController
{
    public function expiry(ListExpiryReportRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->builder();

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return StockResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function slowMovers(ListSlowMoversRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->builder();

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return StockResource::collection($query->paginate($request->perPage())->withQueryString());
    }
}
