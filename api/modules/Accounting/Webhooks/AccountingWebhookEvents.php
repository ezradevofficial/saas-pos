<?php

namespace Modules\Accounting\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Events\DocumentPosted;
use Modules\Accounting\Models\Document;

/**
 * HOOK-01: Accounting's public webhook events (docs/webhooks.md). A
 * customer invoice or credit note is final once posted (Accounting has no
 * separate approval step), so HOOK-01's "invoice approved" is
 * `invoice.posted.v1`. The document's number and amounts are read from
 * Accounting's own table in the fan-out job, never in the posting request.
 * Received with `accounting.invoice.view` at the document's company or branch.
 */
final class AccountingWebhookEvents
{
    private const TYPES = ['customer_invoice' => 'invoice.posted.v1', 'customer_credit' => 'credit_note.posted.v1'];

    private const FIELDS = ['document_id', 'kind', 'number', 'company_id', 'branch_id', 'party_id', 'issue_date', 'due_date', 'currency',
        'subtotal_minor', 'tax_minor', 'total_minor', 'credits_document_id', 'posted_at'];

    public static function register(WebhookCatalogue $catalogue): void
    {
        foreach (self::TYPES as $kind => $key) {
            $catalogue->register(new WebhookEventType(
                key: $key,
                module: AccountingServiceProvider::MODULE,
                event: DocumentPosted::class,
                permission: 'accounting.invoice.view',
                capture: fn (DocumentPosted $e) => $e->kind !== $kind ? null : new WebhookOccurrence(['document_id' => $e->documentId, 'kind' => $e->kind, 'company_id' => $e->companyId], $e->companyId),
                fields: self::FIELDS,
                resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                    $document = Document::query()->find($occurrence->data['document_id'] ?? null);

                    if ($document === null || $document->status !== Document::POSTED) {
                        return null;
                    }

                    return $occurrence->at($document->company_id, $document->branch_id)->with([
                        'document_id' => $document->id,
                        'kind' => $document->kind,
                        'number' => $document->number,
                        'company_id' => $document->company_id,
                        'branch_id' => $document->branch_id,
                        'party_id' => $document->party_id,
                        'issue_date' => $document->issue_date?->toDateString(),
                        'due_date' => $document->due_date?->toDateString(),
                        'currency' => $document->currency,
                        'subtotal_minor' => (string) $document->subtotal_minor,
                        'tax_minor' => (string) $document->tax_minor,
                        'total_minor' => (string) $document->total_minor,
                        'credits_document_id' => $document->credits_document_id,
                        'posted_at' => $document->posted_at?->toIso8601ZuluString(),
                    ]);
                },
            ));
        }
    }
}
