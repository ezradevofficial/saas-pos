<?php

namespace App\Core\Fiscal\Http\Resources;

use App\Core\Fiscal\Models\FiscalSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fiscal submission for the back office: status, attempts, the
 * authority's references once accepted and the last safe error. The
 * document payload is included only on the detail route.
 *
 * @mixin FiscalSubmission
 */
class FiscalSubmissionResource extends JsonResource
{
    public bool $withPayload = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'country' => $this->country,
            'driver' => $this->driver,
            'source' => $this->source,
            'document_type' => $this->document_type,
            'document_id' => $this->document_id,
            'document_number' => $this->document_number,
            'invoice_number' => $this->invoice_no,
            'original_submission_id' => $this->original_submission_id,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'authority' => (object) ($this->authority ?? []),
            'error_code' => $this->error_code,
            'error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'payload' => $this->when($this->withPayload, fn () => $this->payload),
        ];
    }
}
