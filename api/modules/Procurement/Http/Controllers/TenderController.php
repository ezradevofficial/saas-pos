<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Lists\TenderList;
use Modules\Procurement\Http\Requests\AddBidderRequest;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListTendersRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreTenderRequest;
use Modules\Procurement\Http\Requests\UpdateTenderRequest;
use Modules\Procurement\Http\Resources\TenderResource;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Tenders\Bidders;
use Modules\Procurement\Tenders\Opening;
use Modules\Procurement\Tenders\Tenders;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-06 to PRC-10: tenders, their bidders and the committee opening. */
class TenderController
{
    public function __construct(
        private readonly Tenders $tenders,
        private readonly Bidders $bidders,
        private readonly Opening $opening,
    ) {}

    public function index(ListTendersRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Tender::query()->with(TenderList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return TenderResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($procurementTender);
    }

    public function store(StoreTenderRequest $request): JsonResponse
    {
        return $this->detail($this->tenders->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateTenderRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->tenders->update($procurementTender, $request->validated()));
    }

    public function publish(DocumentActionRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->tenders->publish($procurementTender));
    }

    public function cancel(DocumentActionRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->tenders->cancel($procurementTender, $request->user(), (string) $request->validated('reason')));
    }

    public function addBidder(AddBidderRequest $request, Tender $procurementTender): JsonResponse
    {
        $this->bidders->add($procurementTender, $request->validated(), $request->user());

        return $this->detail($procurementTender)->response()->setStatusCode(201);
    }

    /** PRC-10: one opener's confirmation; the second opens the bids. */
    public function open(DocumentActionRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->opening->confirm($procurementTender, $request->user()));
    }

    public function resend(DocumentActionRequest $request, Bidder $procurementBidder): TenderResource
    {
        $this->bidders->resend($procurementBidder);

        return $this->detail(Tender::query()->findOrFail($procurementBidder->tender_id));
    }

    public function removeBidder(DocumentActionRequest $request, Bidder $procurementBidder): TenderResource
    {
        $this->bidders->remove($procurementBidder);

        return $this->detail(Tender::query()->findOrFail($procurementBidder->tender_id));
    }

    private function detail(Tender $tender): TenderResource
    {
        return TenderResource::make($tender->refresh()->load(TenderList::RELATIONS))->detail();
    }
}
