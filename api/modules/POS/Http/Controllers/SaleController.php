<?php

namespace Modules\POS\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\POS\Http\Lists\SaleList;
use Modules\POS\Http\Requests\ListSalesRequest;
use Modules\POS\Http\Requests\ShowPosRecordRequest;
use Modules\POS\Http\Resources\SaleResource;
use Modules\POS\Models\Sale;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** POS-12: sales in the back office, scoped by location (RBAC-04). */
class SaleController
{
    public function index(ListSalesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(Sale::query()->with(SaleList::RELATIONS), 'sold_at');
        $request->applySearch($query, ['receipt_number' => 'receipt_number']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return SaleResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowPosRecordRequest $request, Sale $posSale): SaleResource
    {
        return SaleResource::make($posSale->load([...SaleList::RELATIONS, 'lines', 'payments', 'voidRecord', 'refunds']))->detail();
    }
}
