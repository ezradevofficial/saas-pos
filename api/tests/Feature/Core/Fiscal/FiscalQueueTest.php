<?php

namespace Tests\Feature\Core\Fiscal;

use App\Core\Fiscal\FiscalAlert;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\Jobs\ProcessFiscalQueue;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFiscal;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\Support\Fiscal\TestFiscalSource;
use Tests\TestCase;

// Concept note 7.2, POS-10: the server's fiscal queue. A document is
// queued once, sent until accepted (backoff, never given up, an alert
// after the country's delay), refused documents are never retried by
// themselves and alert at once, a missing fiscal code stops a document
// with a clear reason, and credit notes wait for their sale.
class FiscalQueueTest extends TestCase
{
    use BuildsFiscal, RefreshTenantDatabase, WithoutOwnerConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFiscal();
    }

    private function queue(): FiscalQueue
    {
        return app(FiscalQueue::class);
    }

    private function enqueue(string $type, string $id): ?FiscalSubmission
    {
        return $this->inTenant(fn () => $this->queue()->enqueue(TestFiscalSource::KEY, $type, $id, $this->acme->id));
    }

    private function submission(string $type, string $id): FiscalSubmission
    {
        return $this->inTenant(fn () => $this->queue()->find(TestFiscalSource::KEY, $type, $id)->refresh());
    }

    private function process(CarbonImmutable $at): void
    {
        Artisan::call('fiscal:process', ['--at' => $at->toIso8601String()]);
    }

    private function alerts(string $event): int
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('event_type', $event)->where('user_id', $this->owner->id)->count());
    }

    public function test_nothing_is_queued_while_transmission_is_off(): void
    {
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);

        $this->assertNull($this->enqueue('sale', $sale));
        $this->assertSame(0, $this->inTenant(fn () => FiscalSubmission::query()->count()));
    }

    public function test_a_sale_is_queued_once_and_accepted_with_the_authoritys_references(): void
    {
        $this->enableFakeFiscal();
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);

        $first = $this->enqueue('sale', $sale);
        $again = $this->enqueue('sale', $sale);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $first->invoice_no);
        $accepted = $this->submission('sale', $sale);
        $this->assertSame('accepted', $accepted->status);
        $this->assertSame(1, $accepted->attempts);
        $this->assertNotEmpty($accepted->authority['receipt_signature']);
        $this->assertNotEmpty($accepted->authority['qr']);

        $status = $this->inTenant(fn () => $this->queue()->statusFor(TestFiscalSource::KEY, 'sale', $sale));
        $this->assertSame('accepted', $status['status']);
        $this->assertSame($accepted->authority['receipt_signature'], $status['authority']['receipt_signature']);
        $this->assertNull($this->inTenant(fn () => $this->queue()->statusFor(TestFiscalSource::KEY, 'sale', (string) Str::uuid7())));

        // The next document takes the next fiscal invoice number.
        $next = (string) Str::uuid7();
        TestFiscalSource::sale($next, $this->acme->id, [['Rice 2kg', $this->vat->id, '12.5', 45000, 5000]]);
        $this->assertSame(2, $this->enqueue('sale', $next)->invoice_no);
    }

    public function test_no_answer_is_retried_with_backoff_for_ever_and_alerted_once(): void
    {
        $this->enableFakeFiscal();
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['RETRY item', $this->vat->id, '12.5', 22500, 2500]]);
        $start = CarbonImmutable::now();

        $this->enqueue('sale', $sale);
        $retrying = $this->submission('sale', $sale);
        $this->assertSame('retrying', $retrying->status);
        $this->assertSame(1, $retrying->attempts);
        $this->assertEqualsWithDelta($start->addSeconds(60)->timestamp, $retrying->next_attempt_at->timestamp, 5);
        $this->assertSame('fake_unavailable', $retrying->error_code);

        $this->withoutOwnerConnection();
        // Not due yet: untouched.
        $this->process($start->addSeconds(30));
        $this->assertSame(1, $this->submission('sale', $sale)->attempts);

        $this->process($start->addSeconds(61));
        $this->assertSame(2, $this->submission('sale', $sale)->attempts);
        $this->assertEqualsWithDelta($start->addSeconds(61 + 300)->timestamp, $this->submission('sale', $sale)->next_attempt_at->timestamp, 5);
        $this->assertSame(0, $this->alerts(FiscalAlert::DELAYED));

        // After the country's alert delay (6 hours): one alert, still retried.
        $this->travelTo($start->addHours(7));
        $this->process($start->addHours(7));
        $this->process($start->addHours(9));
        $late = $this->submission('sale', $sale);
        $this->assertSame('retrying', $late->status);
        $this->assertSame(4, $late->attempts);
        $this->assertNotNull($late->alerted_at);
        $this->assertSame(1, $this->alerts(FiscalAlert::DELAYED));

        // Many more failures: the delay stays at the last backoff step, never given up.
        foreach (range(10, 30) as $hour) {
            $this->process($start->addHours($hour));
        }
        $this->assertSame('retrying', $this->submission('sale', $sale)->status);
        $this->assertSame(1, $this->alerts(FiscalAlert::DELAYED));
    }

    public function test_a_refused_document_alerts_at_once_and_is_retried_only_on_request(): void
    {
        $this->enableFakeFiscal();
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['REJECT item', $this->vat->id, '12.5', 22500, 2500]]);

        $this->enqueue('sale', $sale);
        $rejected = $this->submission('sale', $sale);
        $this->assertSame('rejected', $rejected->status);
        $this->assertNull($rejected->next_attempt_at);
        $this->assertSame(1, $this->alerts(FiscalAlert::REJECTED));

        $this->process(CarbonImmutable::now()->addDays(3));
        $this->assertSame(1, $this->submission('sale', $sale)->attempts);
        $this->assertSame([], app(DueTenants::class)->withDueFiscalSubmissions(CarbonImmutable::now()->addDays(3), CarbonImmutable::now()));

        // Fixed at the source, then retried by a person.
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);
        $this->inTenant(fn () => $rejected->forceFill(['payload' => TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]])])->saveQuietly());
        $this->postJson("/api/v1/fiscal-submissions/{$rejected->id}/retry", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/fiscal-submissions/{$rejected->id}/retry", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'already_accepted');
    }

    public function test_a_line_without_a_fiscal_code_is_rejected_naming_the_item(): void
    {
        $this->enableFakeFiscal();
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500], ['Cooking oil', $this->unbanded->id, '12.5', 33750, 3750]]);

        $this->enqueue('sale', $sale);

        $rejected = $this->submission('sale', $sale);
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('fiscal_code_missing', $rejected->error_code);
        $this->assertStringContainsString('Cooking oil', $rejected->last_error);
        $this->assertStringContainsString('VAT_NB', $rejected->last_error);
        $this->assertSame(1, $this->alerts(FiscalAlert::REJECTED));

        // Once the tax code has its band, a retry goes through.
        $this->patchJson("/api/v1/tax-codes/{$this->unbanded->id}", ['fiscal_code' => 'B'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/fiscal-submissions/{$rejected->id}/retry", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_a_refund_queues_its_sale_first_and_waits_until_the_sale_is_accepted(): void
    {
        $this->enableFakeFiscal();
        $sale = (string) Str::uuid7();
        $refund = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['RETRY item', $this->vat->id, '12.5', 22500, 2500]]);
        TestFiscalSource::creditNote('refund', $refund, $sale, $this->acme->id, [['RETRY item', $this->vat->id, '12.5', 11250, 1250]]);

        $credit = $this->enqueue('refund', $refund);

        $original = $this->submission('sale', $sale);
        $this->assertSame($original->id, $credit->original_submission_id);
        $this->assertSame(1, $original->invoice_no);
        $this->assertSame(2, $credit->invoice_no);
        $waiting = $this->submission('refund', $refund);
        $this->assertSame('queued', $waiting->status);
        $this->assertSame(0, $waiting->attempts);

        // The sale goes through; then the credit note does.
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);
        $this->inTenant(fn () => $original->forceFill(['payload' => TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]])])->saveQuietly());
        $this->inTenant(fn () => $waiting->forceFill(['payload' => TestFiscalSource::creditNote('refund', $refund, $sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 11250, 1250]])])->saveQuietly());
        $this->process(CarbonImmutable::now()->addMinutes(2));
        $this->assertSame('accepted', $this->submission('sale', $sale)->status);
        $this->process(CarbonImmutable::now()->addMinutes(5));
        $this->assertSame('accepted', $this->submission('refund', $refund)->status);
    }

    public function test_a_submission_left_sending_by_a_dead_worker_is_retried(): void
    {
        $this->enableFakeFiscal();
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);
        Bus::fake();
        $this->enqueue('sale', $sale);
        $this->inTenant(fn () => $this->submission('sale', $sale)->forceFill(['status' => 'sending'])->saveQuietly());

        $this->withoutOwnerConnection();
        $later = CarbonImmutable::now()->addMinutes(15);
        $this->assertSame([$this->owner->tenant_id], app(DueTenants::class)->withDueFiscalSubmissions($later, $later->subMinutes(10)));
        $this->process($later);
        Bus::assertDispatched(ProcessFiscalQueue::class, fn (ProcessFiscalQueue $job) => $job->tenantId === $this->owner->tenant_id && $job->queue === 'fiscal');

        $this->inTenant(fn () => $this->queue()->process($later));
        $this->assertSame('accepted', $this->submission('sale', $sale)->status);
    }
}
