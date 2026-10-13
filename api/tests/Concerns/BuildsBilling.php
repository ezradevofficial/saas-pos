<?php

namespace Tests\Concerns;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * The platform's billing for tests (ADR 016): the operator tenant with a
 * KE (and CD) selling company, fake fiscal transmission and fake payment
 * drivers, and the organisation (BuildsOrganisation) on a Starter trial.
 */
trait BuildsBilling
{
    use BuildsOrganisation;

    protected const STARTER = ['modules' => ['inventory', 'loyalty', 'pos'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly', 'bundle' => 'starter'];

    protected string $operatorId;

    protected function setUpBilling(string $at = '2026-11-01 08:00:00'): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
        $this->artisan('platform:operator', ['--country' => ['KE', 'CD'], '--fake' => true])->assertSuccessful();
        app(Operator::class)->forget();
        $this->operatorId = (string) app(Operator::class)->id();
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
    }

    protected function subscription(): Subscription
    {
        return $this->inTenant(fn () => Subscription::query()->firstOrFail());
    }

    /** @return list<BillingInvoice> the tenant's copies, oldest first */
    protected function invoices(): array
    {
        return $this->inTenant(fn () => BillingInvoice::query()->orderBy('issued_at')->orderBy('number')->get()->all());
    }

    protected function inOperator(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->operatorId, $fn);
    }

    protected function operatorInvoice(string $id): OperatorInvoice
    {
        return $this->inOperator(fn () => OperatorInvoice::query()->findOrFail($id));
    }

    /** Subscribe from the trial and pay the first invoice with the fake M-Pesa: an active subscription from today. */
    protected function activate(): BillingInvoice
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $this->payWithMpesa($invoice);

        return $this->inTenant(fn () => BillingInvoice::query()->findOrFail($invoice));
    }

    /** Pay an invoice by STK push (the fake driver: pending, then paid when checked). */
    protected function payWithMpesa(string $invoiceId, string $phone = '0712345678'): TestResponse
    {
        $payment = $this->postJson("/api/v1/subscription/invoices/{$invoiceId}/pay", ['provider' => 'mpesa_ke', 'phone' => $phone], $this->headersFor())->assertCreated();
        $this->assertSame('pending', $payment->json('data.status'));

        return $this->getJson('/api/v1/subscription/payments/'.$payment->json('data.id'), $this->headersFor())->assertOk();
    }

    /** Move the clock; the owner signs in again (sessions time out when idle, AUTH-09). */
    protected function travelToDay(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
        $this->organisationTokens = [];
    }

    protected function cycle(string $at): void
    {
        $this->travelToDay($at);
        $this->artisan('billing:cycle')->assertSuccessful();
    }

    /** @return list<string> event types of the in-app notifications the owner received, oldest first */
    protected function ownerNotices(): array
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->owner->id)->orderBy('created_at')->orderBy('id')->pluck('event_type')->all());
    }
}
