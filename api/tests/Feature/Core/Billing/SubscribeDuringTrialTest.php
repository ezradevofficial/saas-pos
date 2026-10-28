<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Invoicing\BillingInvoicePdf;
use App\Core\Billing\Models\Subscription;
use App\Core\Notifications\Models\InAppNotification;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * SUB-02, SUB-03, SUB-05: "Subscribe now" during the trial. The first
 * invoice is issued at once and falls due at the trial end; its period
 * starts at the trial end, so no trial day is charged. The tenant stays in
 * trial (subscribed) until then; paying early never moves the period, and
 * renewals, reminders and dunning follow from the trial end. A plan that
 * bills as the current one is never saved as a change (422 `no_change`).
 *
 * The clock: 1 Nov 2026 08:00 UTC, trial (Starter, KES 3,900 a month) until 15 Nov 08:00.
 */
class SubscribeDuringTrialTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    public function test_subscribing_early_monthly_invoices_the_first_period_from_the_trial_end_and_keeps_the_trial(): void
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();
        $invoice->assertJsonPath('data.period_start', '2026-11-15')->assertJsonPath('data.period_end', '2026-12-15');

        $first = $this->invoices()[0];
        $this->assertSame(['first', 390000, '2026-11-01', '2026-11-15', '2026-12-15', '2026-11-15'], [
            $first->kind, $first->total_minor, $first->issued_at->toDateString(), $first->period_start->toDateString(), $first->period_end->toDateString(), $first->due_at->toDateString(),
        ]);

        $subscription = $this->subscription();
        $this->assertSame([Subscription::TRIAL, '2026-11-15', '2026-12-15'], [$subscription->status, $subscription->current_period_start->toDateString(), $subscription->current_period_end->toDateString()]);

        // Still a trial with its days left, shown as subscribed; the next invoice is the first one, due at the trial end.
        $this->getJson('/api/v1/subscription/status', $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'trial')->assertJsonPath('data.subscribed', true)->assertJsonPath('data.trial_days_left', 14);
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk()
            ->assertJsonPath('data.current_period_start', '2026-11-15')
            ->assertJsonPath('data.next_invoice.date', '2026-11-15')
            ->assertJsonPath('data.next_invoice.amount', ['amount_minor' => 390000, 'currency' => 'KES']);
        $this->getJson('/api/v1/onboarding/trial', $this->headersFor())->assertOk();

        // The PDF prints the period from the trial end (its last day is the day before the end).
        $this->assertSame('2026-11-15 – 2026-12-14', $this->inTenant(fn () => app(BillingInvoicePdf::class)->data($first, 'en'))['document']['period']);

        // Set in Geist, as the design system and the template ask, never in dompdf's DejaVu.
        $pdf = $this->get("/api/v1/subscription/invoices/{$first->id}/pdf", $this->headersFor())->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('#/BaseFont\s*/[A-Z]{6}\+Geist#', $pdf);
        $this->assertStringNotContainsString('DejaVu', $pdf);

        // Subscribing twice is refused, whatever the trial still has to run.
        $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'already_subscribed');
        $this->assertCount(1, $this->invoices());
    }

    public function test_paid_before_the_trial_end_it_stays_a_trial_then_becomes_active_and_renews_from_the_trial_end(): void
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $this->travelToDay('2026-11-03 10:00:00');
        $this->payWithMpesa($invoice)->assertJsonPath('data.status', 'succeeded');

        // Paying early moves nothing: still in trial, the same period.
        $subscription = $this->subscription();
        $this->assertSame([Subscription::TRIAL, '2026-11-15', '2026-12-15'], [$subscription->status, $subscription->current_period_start->toDateString(), $subscription->current_period_end->toDateString()]);
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk()->assertJsonPath('data.next_invoice.date', '2026-12-15');

        // No trial-ending reminder once paid; nothing is invoiced before the period ends.
        $this->cycle('2026-11-08 09:00:00');
        $this->cycle('2026-11-12 09:00:00');
        $this->assertNotContains('core.subscription.trial_ending', $this->ownerNotices());
        $this->assertSame(Subscription::TRIAL, $this->subscription()->status);

        // The trial ends: active, no second invoice.
        $this->cycle('2026-11-15 09:00:00');
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);
        $this->assertCount(1, $this->invoices());

        // Renewal reminders and the renewal follow from the trial end.
        $this->cycle('2026-12-08 09:00:00');
        $this->assertContains('core.subscription.renewal_due', $this->ownerNotices());
        $this->cycle('2026-12-15 01:00:00');
        $renewal = $this->invoices()[1];
        $this->assertSame(['renewal', '2026-12-15', '2027-01-15', '2026-12-15'], [$renewal->kind, $renewal->period_start->toDateString(), $renewal->period_end->toDateString(), $renewal->due_at->toDateString()]);
    }

    public function test_unpaid_at_the_trial_end_it_is_reminded_then_past_due_and_paying_keeps_the_period(): void
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');

        // SUB-05: the trial-ending reminders name the first invoice's amount and the trial end.
        $this->cycle('2026-11-08 09:00:00');
        $this->cycle('2026-11-12 09:00:00');
        $reminders = $this->notices('core.subscription.trial_ending');
        $this->assertSame([['7', '2026-11-15'], ['3', '2026-11-15']], array_map(fn ($d) => [$d['days'], $d['date']], $reminders));
        $this->assertStringContainsString('3,900.00', $reminders[0]['amount']);

        // On the trial end: still a trial, told it falls due today (as an invoice the cycle issues is); no second invoice.
        $this->cycle('2026-11-15 09:00:00');
        $this->assertSame(Subscription::TRIAL, $this->subscription()->status);
        $this->assertSame(['7', '3'], array_column($this->notices('core.subscription.trial_ending'), 'days'));
        $this->assertSame([['0', '2026-11-15']], array_map(fn ($d) => [$d['days'], $d['date']], $this->notices('core.subscription.renewal_due')));
        $this->assertCount(1, $this->invoices());

        // Overdue from the next day; paid, active again with the period unchanged.
        $this->cycle('2026-11-16 09:00:00');
        $this->assertSame(Subscription::PAST_DUE, $this->subscription()->status);
        $this->payWithMpesa($invoice);
        $subscription = $this->subscription();
        $this->assertSame([Subscription::ACTIVE, '2026-11-15', '2026-12-15'], [$subscription->status, $subscription->current_period_start->toDateString(), $subscription->current_period_end->toDateString()]);
        $this->assertCount(1, $this->invoices());
    }

    public function test_subscribing_early_yearly_invoices_a_year_from_the_trial_end(): void
    {
        $annual = [...self::STARTER, 'cycle' => 'annual'];
        $this->postJson('/api/v1/subscription/change', [...$annual, 'expected_charge' => 0], $this->headersFor())->assertOk()->assertJsonPath('data.quote.kind', 'trial');

        $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();
        $first = $this->invoices()[0];
        $subscription = $this->subscription();
        $this->assertSame(['2026-11-15', '2027-11-15', '2026-11-15'], [$first->period_start->toDateString(), $first->period_end->toDateString(), $first->due_at->toDateString()]);
        $this->assertSame([Subscription::TRIAL, '2026-11-15', '2027-11-15', $subscription->period_amount_minor], [$subscription->status, $subscription->current_period_start->toDateString(), $subscription->current_period_end->toDateString(), $first->total_minor]);

        // Paid after the trial end: active, the year unchanged.
        $this->cycle('2026-11-15 09:00:00');
        $this->payWithMpesa($first->id);
        $this->assertSame([Subscription::ACTIVE, '2027-11-15'], [$this->subscription()->status, $this->subscription()->current_period_end->toDateString()]);
    }

    public function test_after_the_trial_end_subscribing_starts_the_period_today(): void
    {
        // The trial ended an hour ago; the cycle has not run yet.
        $this->travelToDay('2026-11-15 09:00:00');
        $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();
        $first = $this->invoices()[0];
        $this->assertSame(['2026-11-15', '2026-12-15', '2026-11-15'], [$first->period_start->toDateString(), $first->period_end->toDateString(), $first->due_at->toDateString()]);

        $this->payWithMpesa($first->id);
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);
    }

    public function test_a_plan_change_during_the_subscribed_trial_charges_the_first_period_and_schedules_for_its_end(): void
    {
        $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();
        $big = [...self::STARTER, 'modules' => ['inventory', 'loyalty', 'payroll', 'pos'], 'quantities' => ['outlet' => 1, 'employee' => 20]];

        // Payroll for 20 employees is KES 2,000 a month: the whole first period, 30 of 30 days.
        $quote = $this->postJson('/api/v1/subscription/quote', $big, $this->headersFor())->assertOk();
        $quote->assertJsonPath('data.kind', 'upgrade')->assertJsonPath('data.charge_now', 200000)
            ->assertJsonPath('data.period_days', 30)->assertJsonPath('data.remaining_days', 30);
        $this->postJson('/api/v1/subscription/change', [...$big, 'expected_charge' => 200000], $this->headersFor())->assertOk();

        $upgrade = $this->invoices()[1];
        $this->assertSame(['upgrade', 200000, '2026-11-15', '2026-12-15', '2026-11-15'], [
            $upgrade->kind, $upgrade->total_minor, $upgrade->period_start->toDateString(), $upgrade->period_end->toDateString(), $upgrade->due_at->toDateString(),
        ]);
        $subscription = $this->subscription();
        $this->assertSame([Subscription::TRIAL, '2026-11-15', 590000], [$subscription->status, $subscription->current_period_start->toDateString(), $subscription->period_amount_minor]);

        // Going back down waits for the end of the first period, and the page says so.
        $this->postJson('/api/v1/subscription/quote', self::STARTER, $this->headersFor())->assertOk()
            ->assertJsonPath('data.kind', 'scheduled')->assertJsonPath('data.effective_on', '2026-12-15');
        $this->postJson('/api/v1/subscription/change', [...self::STARTER, 'expected_charge' => 0], $this->headersFor())->assertOk()
            ->assertJsonPath('data.subscription.pending_change.effective_on', '2026-12-15');
    }

    public function test_an_identical_plan_is_refused_as_no_change_and_never_saved(): void
    {
        $this->activate();

        foreach ([self::STARTER, [...self::STARTER, 'quantities' => ['outlet' => 1, 'device' => 2]]] as $plan) {
            $this->postJson('/api/v1/subscription/quote', $plan, $this->headersFor())->assertOk()
                ->assertJsonPath('data.kind', 'none')->assertJsonPath('data.effective_on', null);
            $this->postJson('/api/v1/subscription/change', [...$plan, 'expected_charge' => 0], $this->headersFor())
                ->assertStatus(422)->assertJsonPath('code', 'no_change')->assertJsonPath('message', __('billing.errors.no_change'));
        }

        $this->postJson('/api/v1/subscription/change', [...self::STARTER, 'expected_charge' => 0], $this->headersFor() + ['Accept-Language' => 'fr'])
            ->assertStatus(422)->assertJsonPath('code', 'no_change');
        $this->assertNull($this->subscription()->pending_change);
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk()->assertJsonPath('data.pending_change', null);

        // Paying the first invoice never records a change.
        $this->assertSame([], $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.subscription.change')->pluck('id')->all()));
    }

    /** @return list<array<string, string>> the data of the owner's in-app notices of $event, oldest first */
    private function notices(string $event): array
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->owner->id)->where('event_type', $event)->orderBy('created_at')->orderBy('id')->pluck('data')->all());
    }
}
