<?php

namespace App\Core\DocumentTemplates;

use App\Core\Audit\Auditor;
use App\Core\DocumentTemplates\Jobs\SendDocumentEmail;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TPL-04: email a document with its PDF. Queued (SendDocumentEmail),
 * rate-limited per user (PER_MINUTE and PER_DAY; 429
 * `too_many_emails`), and audited as `core.document.email` on the record
 * with the recipient (AUD-01).
 */
class DocumentEmails
{
    public const PER_MINUTE = 5;

    public const PER_DAY = 200;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    public function send(DocumentData $document, Model $record, string $email, string $locale, User $by): void
    {
        $minute = 'document-email:m:'.$by->id;
        $day = 'document-email:d:'.$by->id;

        if (RateLimiter::tooManyAttempts($minute, self::PER_MINUTE) || RateLimiter::tooManyAttempts($day, self::PER_DAY)) {
            throw new ApiException(429, 'too_many_emails', __('templates.errors.too_many_emails'));
        }

        RateLimiter::hit($minute, 60);
        RateLimiter::hit($day, 86400);

        $this->auditor->record('core.document.email', $record, null, [
            'document_type' => $document->type, 'number' => $document->number(), 'to' => $email, 'language' => $locale,
        ]);

        SendDocumentEmail::dispatch($this->tenants->require(), $document->type, $document->recordId, $email, $locale)->afterCommit();
    }
}
