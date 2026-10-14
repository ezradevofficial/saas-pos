<?php

namespace Modules\Procurement\Http\Requests;

/**
 * PUT procurement/bids/{id}/scores (PRC-11): an opened bid, by a committee
 * evaluator holding `procurement.tender.evaluate` where the tender is;
 * `{scores: [{criterion_id, score (0..the criterion's maximum), comment?}]}`.
 */
class ScoreBidRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('evaluate');
    }

    public function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'min:1', 'max:30'],
            'scores.*.criterion_id' => ProcurementRules::id('procurement_tender_criteria', required: true),
            'scores.*.score' => ['required', 'numeric', 'min:0', 'max:1000'],
            'scores.*.comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
