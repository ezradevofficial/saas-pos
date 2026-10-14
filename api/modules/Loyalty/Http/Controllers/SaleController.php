<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Loyalty\Http\Lists\SaleList;
use Modules\Loyalty\Http\Requests\ListLoyaltyRequest;
use Modules\Loyalty\Http\Resources\LoyaltySaleResource;
use Modules\Loyalty\Models\LoyaltySale;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** LOY-12, H2: POS sales Loyalty flagged, for review (`loyalty.sale.view`). */
class SaleController
{
    public function index(ListLoyaltyRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(LoyaltySale::query()->with(SaleList::RELATIONS));

        if ($request->validated('flagged', '1') === '1') {
            $query->whereRaw('jsonb_array_length(flags) > 0');
        }

        if ($request->filled('flag')) {
            $query->whereRaw('flags @> ?::jsonb', [json_encode([['code' => $request->validated('flag')]])]);
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $query->where('receipt_number', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return LoyaltySaleResource::collection($query->paginate($request->perPage())->withQueryString());
    }
}
