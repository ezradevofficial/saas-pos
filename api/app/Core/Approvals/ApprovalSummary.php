<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Throwable;

/**
 * APR-04: an approval keeps the document's number, title and amount from
 * the moment it was requested, but a pending request must never show an
 * approver a stale figure (a fuel shift reopened after late sales, a
 * reconciliation resubmitted). `refresh` re-reads the type's summary for a
 * pending request and stores it when it changed; decided requests keep the
 * figures that were decided on.
 */
class ApprovalSummary
{
    public function __construct(private readonly DocumentTypeRegistry $types) {}

    public function refresh(ApprovalRequest $request): ApprovalRequest
    {
        if (! $request->isPending()) {
            return $request;
        }

        $type = $this->types->find($request->document_type);

        if ($type === null) {
            return $request;
        }

        try {
            $summary = $type->summary($request->document_id);
        } catch (Throwable) {
            return $request;
        }

        // A document that can no longer be read keeps the stored figures.
        if (($summary['number'] ?? null) === null && ($summary['title'] ?? null) === null && ($summary['amount'] ?? null) === null) {
            return $request;
        }

        $amount = is_array($summary['amount'] ?? null) ? $summary['amount'] : null;
        $fresh = [
            'document_number' => is_string($summary['number'] ?? null) ? mb_substr($summary['number'], 0, 100) : $request->document_number,
            'document_title' => is_string($summary['title'] ?? null) ? mb_substr($summary['title'], 0, 255) : $request->document_title,
            'amount_minor' => $amount === null ? $request->amount_minor : $amount['amount_minor'],
            'currency' => $amount === null ? $request->currency : $amount['currency'],
        ];

        $request->forceFill($fresh);

        if ($request->isDirty(array_keys($fresh))) {
            // Quiet: a refresh is not a user edit, so it is not audited or re-timestamped.
            ApprovalRequest::query()->whereKey($request->id)->toBase()->update($fresh);
            $request->syncOriginal();
        }

        return $request;
    }
}
