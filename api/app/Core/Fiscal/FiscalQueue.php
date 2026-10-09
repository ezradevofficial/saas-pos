<?php

namespace App\Core\Fiscal;

use App\Core\Fiscal\Jobs\ProcessFiscalQueue;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The server-side fiscal queue (concept note 7.2, POS-10): modules hand
 * over their documents (enqueue), the queue sends them to the authority
 * through the company's driver, and keeps trying until they are accepted.
 *
 * - Once per document: a repeated event finds the submission it made.
 * - A refund or void is a credit note: its sale is queued first when it
 *   was not, and it waits (without counting attempts) until the sale is
 *   accepted, since it must name the sale's fiscal invoice number.
 * - Fiscal invoice numbers are the company's own sequence, handed out
 *   in order under a row lock on the company's settings.
 * - No answer: retried with `fiscal.backoff`, for ever; after
 *   `fiscal.countries.*.alert_after_hours` the company's fiscal
 *   administrators are alerted once (FiscalAlert).
 * - Refused (by the authority or a local check such as a missing fiscal
 *   code): never retried by itself; alerted at once; a person fixes the
 *   data and retries (`retry`). A line without its tax code or rate is
 *   held as `needs_attention` instead. A retry of a rejected or held
 *   submission rebuilds its payload from the source document.
 * - A submission left `sending` by a dead worker is retried after
 *   `fiscal.stuck_minutes`.
 *
 * Everything runs in the current tenant's context; the authority is
 * called outside database transactions.
 */
class FiscalQueue
{
    /** Local results held as `needs_attention` (fixed in the source data, then retried), not rejected. */
    private const DATA_TO_FIX = ['tax_code_missing', 'tax_rate_missing'];

    public function __construct(
        private readonly FiscalSources $sources,
        private readonly FiscalDrivers $drivers,
        private readonly TenantContext $tenants,
        private readonly FiscalAlert $alerts,
    ) {}

    /**
     * Queue $source's document for its company's authority. Null when the
     * company has not switched transmission on.
     */
    public function enqueue(string $source, string $documentType, string $documentId, string $companyId): ?FiscalSubmission
    {
        $settings = FiscalSettings::query()->where('company_id', $companyId)->where('enabled', true)->first();

        if ($settings === null) {
            return null;
        }

        $existing = $this->find($source, $documentType, $documentId);

        if ($existing !== null) {
            return $existing;
        }

        $document = $this->sources->get($source)->document($documentType, $documentId);
        $original = null;

        if ($documentType !== 'sale') {
            $original = $this->enqueue($source, 'sale', (string) $document->data['original']['id'], $companyId);
        }

        try {
            $submission = $this->store($settings, $source, $documentType, $documentId, $document, $original);
        } catch (UniqueConstraintViolationException) {
            // Another worker queued the same document meanwhile.
            return $this->find($source, $documentType, $documentId);
        }

        if ($submission->wasRecentlyCreated) {
            ProcessFiscalQueue::dispatch($this->tenants->require(), CarbonImmutable::now()->toIso8601String())->afterCommit();
        }

        return $submission;
    }

    /** Whether $companyId transmits its documents (its fiscal settings are switched on). */
    public function transmits(string $companyId): bool
    {
        return FiscalSettings::query()->where('company_id', $companyId)->where('enabled', true)->exists();
    }

    private function store(FiscalSettings $settings, string $source, string $documentType, string $documentId, FiscalDocument $document, ?FiscalSubmission $original): FiscalSubmission
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($settings, $source, $documentType, $documentId, $document, $original) {
            $locked = FiscalSettings::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $existing = $this->find($source, $documentType, $documentId);

            if ($existing !== null) {
                return $existing;
            }

            $invoiceNo = $locked->next_invoice_no;
            $locked->forceFill(['next_invoice_no' => $invoiceNo + 1])->saveQuietly();
            $deadline = config("fiscal.countries.{$locked->country}.deadline_hours");

            return FiscalSubmission::create([
                'company_id' => $locked->company_id,
                'country' => $locked->country,
                'driver' => $locked->driver,
                'source' => $source,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'document_number' => isset($document->data['number']) ? mb_substr((string) $document->data['number'], 0, 80) : null,
                'invoice_no' => $invoiceNo,
                'original_submission_id' => $original?->id,
                'payload' => $document->toArray(),
                'status' => 'queued',
                'next_attempt_at' => CarbonImmutable::now(),
                'deadline_at' => is_numeric($deadline) ? CarbonImmutable::parse($document->data['issued_at'])->addHours((int) $deadline) : null,
            ]);
        });
    }

    /** Send what is due in the current tenant at $at; returns how many submissions were tried. */
    public function process(CarbonImmutable $at): int
    {
        $stale = $at->subMinutes((int) config('fiscal.stuck_minutes', 10));

        FiscalSubmission::query()->where('status', 'sending')->where('updated_at', '<', $stale)->get()
            ->each(fn (FiscalSubmission $submission) => $submission->forceFill(['status' => 'retrying', 'next_attempt_at' => $at])->saveQuietly());

        $count = 0;
        // A new authority call starts only when a whole call still fits in the run.
        $stop = microtime(true) + (int) config('fiscal.run_seconds', 55) - ((int) config('fiscal.etims.timeout', 20) + 5);
        $due = FiscalSubmission::query()->whereIn('status', ['queued', 'retrying'])->where('next_attempt_at', '<=', $at)
            ->orderBy('company_id')->orderBy('invoice_no')->limit((int) config('fiscal.batch', 50))->pluck('id');

        foreach ($due as $id) {
            // Stay inside the worker's time limit; the scheduler sends the rest next minute.
            if (microtime(true) >= $stop) {
                break;
            }

            $claimed = $this->claim($id, $at);

            if ($claimed !== null) {
                $this->send($claimed[0], $at, $claimed[1]);
                $count++;
            }
        }

        return $count;
    }

    /** A person asks for a rejected (or waiting) submission to be sent again now. */
    public function retry(FiscalSubmission $submission): FiscalSubmission
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($submission) {
            $locked = FiscalSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['rejected', 'retrying', 'queued', 'needs_attention'], true)) {
                // Never sent as it stood: rebuilt from the source document, so fixed data is sent.
                // A document already sent without an answer keeps the payload the authority may hold.
                if (in_array($locked->status, ['rejected', 'needs_attention'], true)) {
                    $document = $this->sources->get($locked->source)->document($locked->document_type, $locked->document_id);
                    $locked->forceFill(['payload' => $document->toArray()]);
                }

                $locked->fill(['status' => 'queued', 'next_attempt_at' => CarbonImmutable::now(), 'alerted_at' => null])->save();
                ProcessFiscalQueue::dispatch($this->tenants->require(), CarbonImmutable::now()->toIso8601String())->afterCommit();
            }

            return $locked;
        });
    }

    public function find(string $source, string $documentType, string $documentId): ?FiscalSubmission
    {
        return FiscalSubmission::query()->where('source', $source)->where('document_type', $documentType)->where('document_id', $documentId)->first();
    }

    /**
     * A document's fiscal state for its receipt: null when it was never
     * queued (transmission off), else its status and, once accepted, the
     * authority's references (for the receipt and its QR code).
     *
     * @return array{status: string, invoice_number: int, accepted_at: ?string, authority: array<string, scalar|null>}|null
     */
    public function statusFor(string $source, string $documentType, string $documentId): ?array
    {
        $submission = $this->find($source, $documentType, $documentId);

        if ($submission === null) {
            return null;
        }

        return [
            'status' => $submission->status === 'accepted' ? 'accepted' : ($submission->status === 'rejected' ? 'rejected' : 'pending'),
            'invoice_number' => $submission->invoice_no,
            'accepted_at' => $submission->accepted_at?->toIso8601String(),
            'authority' => $submission->status === 'accepted' ? (array) $submission->authority : [],
        ];
    }

    /** @return array{0: FiscalSubmission, 1: string}|null the submission marked `sending`, and its status before */
    private function claim(string $id, CarbonImmutable $at): ?array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id, $at) {
            $submission = FiscalSubmission::query()->whereKey($id)->whereIn('status', ['queued', 'retrying'])
                ->where('next_attempt_at', '<=', $at)->lock('for update skip locked')->first();

            if ($submission === null) {
                return null;
            }

            $before = $submission->status;
            $submission->forceFill(['status' => 'sending', 'last_attempt_at' => CarbonImmutable::now()])->saveQuietly();

            return [$submission, $before];
        });
    }

    private function send(FiscalSubmission $submission, CarbonImmutable $at, string $before): void
    {
        $settings = FiscalSettings::query()->where('company_id', $submission->company_id)->first();

        // Transmission switched off meanwhile: wait, without counting an attempt.
        if ($settings === null || ! $settings->enabled || $settings->driver !== $submission->driver) {
            $this->wait($submission, $at->addMinutes(15));

            return;
        }

        if ($submission->isCreditNote() && in_array($submission->original?->status, ['rejected', 'needs_attention'], true)) {
            $this->hold($submission, new NeedsAttention('original_not_accepted', __('fiscal.errors.original_not_accepted')));

            return;
        }

        if ($submission->isCreditNote() && $submission->original?->status !== 'accepted') {
            $this->wait($submission, $at->addSeconds((int) config('fiscal.wait_for_original_seconds', 120)));

            return;
        }

        $driver = $this->drivers->get($submission->driver);

        try {
            $result = $submission->isCreditNote()
                ? $driver->submitCreditNote($submission, $settings)
                : $driver->submitInvoice($submission, $settings);
        } catch (NeedsAttention $e) {
            $this->hold($submission, $e);

            return;
        } catch (LocalRejection $e) {
            // A line without its tax code or rate is data to fix, not a refusal: held for a person.
            if (in_array($e->reason, self::DATA_TO_FIX, true)) {
                $this->hold($submission, new NeedsAttention($e->reason, $e->getMessage()));

                return;
            }

            $result = FiscalResult::rejected($e->reason, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $result = FiscalResult::retry('error', __('fiscal.errors.unexpected'));
        }

        if ($result->requestHash !== null) {
            $submission->forceFill(['request_hash' => $result->requestHash]);
        }

        match ($result->status) {
            'accepted' => $submission->fill([
                'status' => 'accepted',
                'attempts' => $submission->attempts + 1,
                'authority' => $result->authority,
                'accepted_at' => CarbonImmutable::now(),
                'error_code' => null,
                'last_error' => null,
            ])->save(),
            'rejected' => $this->reject($submission, $result),
            default => $this->later($submission, $result, $at, $before),
        };
    }

    /** Held until a person decides (NeedsAttention); alerted once. */
    private function hold(FiscalSubmission $submission, NeedsAttention $e): void
    {
        $alert = $submission->alerted_at === null;

        $submission->fill([
            'status' => 'needs_attention',
            'error_code' => $e->reason,
            'last_error' => $e->getMessage(),
            'next_attempt_at' => null,
            'alerted_at' => $submission->alerted_at ?? CarbonImmutable::now(),
        ])->save();

        if ($alert) {
            $this->alerts->needsAttention($submission);
        }
    }

    private function reject(FiscalSubmission $submission, FiscalResult $result): void
    {
        $submission->fill([
            'status' => 'rejected',
            'attempts' => $submission->attempts + 1,
            'error_code' => $result->code,
            'last_error' => $result->message,
            'next_attempt_at' => null,
            'alerted_at' => CarbonImmutable::now(),
        ])->save();

        $this->alerts->rejected($submission);
    }

    private function later(FiscalSubmission $submission, FiscalResult $result, CarbonImmutable $at, string $before): void
    {
        $backoff = array_values((array) config('fiscal.backoff', [60]));
        $attempts = $submission->attempts + 1;
        $delay = (int) $backoff[min($attempts, count($backoff)) - 1];
        $alertAfter = (int) config("fiscal.countries.{$submission->country}.alert_after_hours", 6);
        $alert = $submission->alerted_at === null && $submission->created_at->lessThanOrEqualTo($at->subHours($alertAfter));

        $submission->forceFill([
            'status' => 'retrying',
            'attempts' => $attempts,
            'next_attempt_at' => $at->addSeconds($delay),
            'error_code' => $result->code,
            'last_error' => $result->message,
            'alerted_at' => $alert ? CarbonImmutable::now() : $submission->alerted_at,
        ]);

        // The first failure is audited; the hourly retries after it are not.
        $before === 'retrying' ? $submission->saveQuietly() : $submission->save();

        if ($alert) {
            $this->alerts->delayed($submission, $alertAfter);
        }
    }

    private function wait(FiscalSubmission $submission, CarbonImmutable $until): void
    {
        $submission->forceFill(['status' => 'queued', 'next_attempt_at' => $until])->saveQuietly();
    }
}
