<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Documents\Rfqs;
use Modules\Procurement\Http\Lists\RfqList;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListRfqsRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreQuoteRequest;
use Modules\Procurement\Http\Requests\StoreRfqRequest;
use Modules\Procurement\Http\Requests\UpdateRfqRequest;
use Modules\Procurement\Http\Resources\OrderResource;
use Modules\Procurement\Http\Resources\Refs;
use Modules\Procurement\Http\Resources\RfqResource;
use Modules\Procurement\Models\Rfq;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-03 to PRC-05: RFQs, the quotes typed in, their comparison and award. */
class RfqController
{
    public const DETAIL = [...RfqList::RELATIONS, 'lines.item', 'lines.uom', 'suppliers.party', 'quotes.party', 'quotes.lines'];

    public function __construct(private readonly Rfqs $rfqs) {}

    public function index(ListRfqsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Rfq::query()->with(RfqList::RELATIONS)->withCount(['suppliers', 'quotes']);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RfqResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Rfq $procurementRfq): RfqResource
    {
        return $this->detail($procurementRfq);
    }

    public function store(StoreRfqRequest $request): JsonResponse
    {
        return $this->detail($this->rfqs->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateRfqRequest $request, Rfq $procurementRfq): RfqResource
    {
        return $this->detail($this->rfqs->update($procurementRfq, $request->validated()));
    }

    public function issue(DocumentActionRequest $request, Rfq $procurementRfq): RfqResource
    {
        return $this->detail($this->rfqs->issue($procurementRfq));
    }

    public function cancel(DocumentActionRequest $request, Rfq $procurementRfq): RfqResource
    {
        return $this->detail($this->rfqs->cancel($procurementRfq, $request->user(), (string) $request->validated('reason')));
    }

    public function quote(StoreQuoteRequest $request): JsonResponse
    {
        $quote = $this->rfqs->quote($request->rfq(), $request->validated(), $request->user());

        return response()->json(['data' => RfqResource::quote($quote->load(['party', 'lines']))], 201);
    }

    /** PRC-05: the comparison grid in base currency. */
    public function comparison(ShowDocumentRequest $request, Rfq $procurementRfq): JsonResponse
    {
        $grid = $this->rfqs->comparison($procurementRfq);
        $currency = $grid['base_currency'];

        return response()->json(['data' => [
            'base_currency' => $currency,
            'lines' => array_map(fn (array $line) => [
                'rfq_line_id' => $line['rfq_line']->id,
                'description' => $line['rfq_line']->description,
                'item' => Refs::item($line['rfq_line']->item),
                'uom' => Refs::uom($line['rfq_line']->uom),
                'qty' => Refs::qty($line['rfq_line']->qty),
                'offers' => array_map(fn (array $offer) => [
                    'quote_id' => $offer['quote_id'],
                    'quote_line_id' => $offer['quote_line_id'],
                    'party_id' => $offer['party_id'],
                    'unit_price' => Refs::money($offer['unit_price_minor'], $offer['currency']),
                    'base_unit_price' => Refs::money($offer['base_unit_price_minor'], $currency),
                    'lead_time_days' => $offer['lead_time_days'],
                    'lowest' => $offer['lowest'],
                    'selected' => $offer['selected'],
                ], $line['offers']),
            ], $grid['lines']),
            'suppliers' => array_map(fn (array $supplier) => [
                'quote_id' => $supplier['quote']->id,
                'party' => Refs::named($supplier['quote']->party),
                'currency' => $supplier['quote']->currency,
                'total' => Refs::money($supplier['quote']->total_minor, $supplier['quote']->currency),
                'base_total' => Refs::money($supplier['quote']->base_total_minor, $supplier['quote']->base_currency),
                'lines_quoted' => $supplier['lines_quoted'],
                'status' => $supplier['quote']->status,
                'valid_until' => Refs::date($supplier['quote']->valid_until),
            ], $grid['suppliers']),
        ]]);
    }

    /** PRC-05: award; answers the draft POs made. */
    public function award(DocumentActionRequest $request, Rfq $procurementRfq): JsonResponse
    {
        $orders = $this->rfqs->award($procurementRfq, (array) $request->validated('selections', []), $request->user());

        return response()->json(['data' => $orders->map(fn ($order) => OrderResource::make($order->load(['party', 'company', 'branch', 'location', 'requester']))->resolve($request))->all()], 201);
    }

    private function detail(Rfq $rfq): RfqResource
    {
        return RfqResource::make($rfq->refresh()->load(self::DETAIL)->loadCount(['suppliers', 'quotes']))->detail();
    }
}
