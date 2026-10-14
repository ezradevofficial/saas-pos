<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\DocumentTemplates\DocumentEmails;
use App\Core\DocumentTemplates\DocumentOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ScoreBidRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Resources\TenderResource;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Printing\ProcurementPrints;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Tenders\Award;
use Modules\Procurement\Tenders\Evaluation;
use Modules\Procurement\Tenders\Seal;

/** PRC-09 to PRC-12: an opened bid's scores, award, attachments and letters. */
class BidController
{
    public function __construct(
        private readonly Evaluation $evaluation,
        private readonly Award $award,
    ) {}

    /** PRC-11: an evaluator's scores of the bid. */
    public function scores(ScoreBidRequest $request, Bid $procurementBid): TenderResource
    {
        $tender = Tender::query()->findOrFail($procurementBid->tender_id);
        $scores = array_map(fn (array $score) => [...$score, 'bid_id' => $procurementBid->id], $request->validated('scores'));

        return $this->tender($this->evaluation->score($tender, $scores, $request->user()));
    }

    /** PRC-11: award the tender to this bid (`reason` when it is not the top-ranked). */
    public function award(DocumentActionRequest $request, Bid $procurementBid): TenderResource
    {
        $tender = Tender::query()->findOrFail($procurementBid->tender_id);

        return $this->tender($this->award->award($tender, $procurementBid->id, $request->validated('reason'), $request->user()));
    }

    /** PRC-09: an attachment of an opened bid, decrypted for a buyer who sees the tender (audited). */
    public function file(ShowDocumentRequest $request, Bid $procurementBid, BidFile $procurementBidFile, Auditor $auditor): Response
    {
        if ($procurementBidFile->bid_id !== $procurementBid->id || $procurementBidFile->removed_at !== null) {
            abort(404);
        }

        if (! $procurementBid->isOpened()) {
            throw ProcurementError::make('bid_sealed', status: 409);
        }

        $tender = Tender::query()->findOrFail($procurementBid->tender_id);
        $bytes = Seal::openBytes($tender, (string) Storage::disk($procurementBidFile->disk)->get($procurementBidFile->path));

        if ($bytes === null) {
            throw ProcurementError::make('bid_tampered', status: 409);
        }

        $auditor->record('procurement.bid_file.download', $procurementBidFile, null, ['bid' => $procurementBid->id, 'name' => $procurementBidFile->name]);

        return response($bytes, 200, [
            'Content-Type' => $procurementBidFile->mime,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($procurementBidFile->name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** PRC-12: the bid's award or regret letter as a PDF. */
    public function letter(ShowDocumentRequest $request, Bid $procurementDecidedBid, ProcurementPrints $prints, DocumentOutput $output): Response
    {
        $document = $prints->letter($this->decided($procurementDecidedBid));

        return response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
        ]);
    }

    /** PRC-12, TPL-04: email the letter to the bidder (or another address). */
    public function emailLetter(DocumentActionRequest $request, Bid $procurementDecidedBid, ProcurementPrints $prints, DocumentEmails $emails): JsonResponse
    {
        $document = $prints->letter($this->decided($procurementDecidedBid));
        $email = (string) ($request->validated('email') ?: $document->email);
        $emails->send($document, $procurementDecidedBid, $email, (string) $request->validated('locale', app()->getLocale()), $request->user());

        return response()->json(['data' => ['sent_to' => $email]], 202);
    }

    private function decided(Bid $bid): Bid
    {
        if (! $bid->isOpened() || $bid->outcome === null || Tender::query()->whereKey($bid->tender_id)->value('status') !== Tender::AWARDED) {
            throw ProcurementError::make('not_awarded', status: 409);
        }

        return $bid;
    }

    private function tender(Tender $tender): TenderResource
    {
        return TenderResource::make($tender->refresh()->load(['company', 'branch', 'creator']))->detail();
    }
}
