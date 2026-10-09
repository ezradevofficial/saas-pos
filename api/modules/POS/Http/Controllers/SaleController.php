<?php

namespace Modules\POS\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\POS\Fiscal\SaleFiscalStatus;
use Modules\POS\Http\Lists\SaleList;
use Modules\POS\Http\Requests\ListSalesRequest;
use Modules\POS\Http\Requests\ReviewSaleRequest;
use Modules\POS\Http\Requests\ShowPosRecordRequest;
use Modules\POS\Http\Resources\SaleResource;
use Modules\POS\Models\Sale;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** POS-12: sales in the back office, scoped by location (RBAC-04). */
class SaleController
{
    public function index(ListSalesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(Sale::query()->with([...SaleList::RELATIONS, 'payments']), 'sold_at');
        $request->applySearch($query, ['receipt_number' => 'receipt_number']);

        // M3: what needs review.
        if ($request->filled('flagged')) {
            $query->whereRaw($request->boolean('flagged') ? 'jsonb_array_length(flags) > 0' : 'jsonb_array_length(flags) = 0');
        }

        if ($request->filled('flag')) {
            $query->whereRaw("flags @> jsonb_build_array(jsonb_build_object('code', ?::text))", [$request->validated('flag')]);
        }

        if ($request->filled('reviewed')) {
            $request->boolean('reviewed') ? $query->whereNotNull('reviewed_at') : $query->whereNull('reviewed_at');
        }
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return SaleResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** M3: a flagged sale acknowledged (`pos.sale.review` at its location), audited. */
    public function review(ReviewSaleRequest $request, Sale $posSale, Auditor $auditor): SaleResource
    {
        abort_unless($request->user()->can('pos.sale.view', $posSale) || $request->user()->can('pos.sale.review', $posSale), 404);
        abort_unless($request->user()->can('pos.sale.review', $posSale), 403);

        if ($posSale->flags === []) {
            throw new ApiException(422, 'not_flagged', __('pos.errors.not_flagged'));
        }

        if ($posSale->reviewed_at === null) {
            $posSale->forceFill(['reviewed_at' => now(), 'reviewed_by' => $request->user()->id])->save();
            $auditor->record('pos.sale.review', $posSale, ['reviewed_at' => null], [
                'reviewed_by' => $request->user()->id, 'flags' => $posSale->flags, 'note' => $request->validated('note'),
            ]);
        }

        return SaleResource::make($posSale->load(SaleList::RELATIONS));
    }

    /** POS-10: the sale's fiscal state in the back office (SaleFiscalStatus), for a viewer of the sale. */
    public function fiscal(ShowPosRecordRequest $request, Sale $posSale, SaleFiscalStatus $status): JsonResponse
    {
        return response()->json(['data' => $status->of($posSale)]);
    }

    public function show(ShowPosRecordRequest $request, Sale $posSale): SaleResource
    {
        return SaleResource::make($posSale->load([...SaleList::RELATIONS, 'lines', 'payments.method', 'voidRecord', 'refunds']))->detail();
    }
}
