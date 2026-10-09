<?php

namespace App\Core\DocumentTemplates\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\DocumentTemplates\DocumentTypes;
use App\Core\DocumentTemplates\Mail\DocumentMail;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;

/**
 * TPL-04: email one document with its PDF attached, in its tenant's
 * context (TenantAware: the record is read under row-level security).
 * The PDF is made when the job runs, with the template published then.
 * Encrypted on the queue (it carries the recipient's address).
 */
class SendDocumentEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public string $tenantId,
        public string $type,
        public string $recordId,
        public string $email,
        public string $locale,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(DataSources $sources, DocumentOutput $output, AuditContext $audit): void
    {
        $audit->reset();
        $source = $sources->find($this->type);
        $document = $source instanceof RecordSource ? $source->load($this->recordId) : null;

        if ($document === null) {
            return;
        }

        App::setLocale($this->locale);
        $replace = [
            'document' => DocumentTypes::label($this->type),
            'number' => $document->number(),
            'company' => (string) ($document->data['company']['legal_name'] ?? $document->data['company']['name'] ?? ''),
        ];

        Mail::to($this->email)->send(new DocumentMail(
            __('templates.mail.subject', $replace),
            __('templates.mail.body', $replace),
            $output->pdf($document),
            $document->fileName(),
            $this->locale,
        ));
    }
}
