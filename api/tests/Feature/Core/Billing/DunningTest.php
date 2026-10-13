<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Http\EnforceSubscription;
use App\Core\Billing\Models\Subscription;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * SUB-05, SUB-06, LIM-04: the dunning timeline (reminders 7 and 3 days
 * before and on the due date, past due, the grace period, read-only, the
 * POS window, the POS stop, payment restoring access at once) and the
 * read-only allow-list over every write route.
 */
class DunningTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    private string $tillToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
        $this->tillToken = $this->inTenant(function () {
            $device = Device::create(['location_id' => $this->locationA->id, 'name' => 'Till A']);
            $device->forceFill(['status' => Device::STATUS_ACTIVE, 'paired_at' => now()])->save();

            return $device->issueToken('Till A', null, null)->plainTextToken;
        });
    }

    private function posUpload(): int
    {
        return $this->postJson('/api/v1/pos/sales', ['sales' => []], ['Authorization' => 'Bearer '.$this->tillToken, 'Accept' => 'application/json'])->getStatusCode();
    }

    private function aWrite(): TestResponse
    {
        return $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Wanjiru', 'roles' => ['customer']], $this->headersFor());
    }

    public function test_the_dunning_timeline_from_reminders_to_read_only_and_back(): void
    {
        $this->activate();
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);

        // 7 and 3 days before the period end, and on the due date: one reminder each.
        $this->cycle('2026-11-24 09:00:00');
        $this->cycle('2026-11-24 15:00:00');
        $this->cycle('2026-11-28 09:00:00');
        $this->assertSame(['core.subscription.renewal_due', 'core.subscription.renewal_due'], array_values(array_filter($this->ownerNotices(), fn ($t) => $t === 'core.subscription.renewal_due')));
        $this->assertSame(['2026-12-01:7', '2026-12-01:3'], $this->subscription()->reminders_sent);

        // The due date: the renewal invoice, a reminder; still active that day.
        $this->cycle('2026-12-01 06:00:00');
        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $this->assertSame(['open', 390000, '2026-12-01'], [$renewal->status, $renewal->total_minor, $renewal->due_at->toDateString()]);
        $this->assertContains('2026-12-01:0', $this->subscription()->reminders_sent);
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);

        // The day after: past due (the grace period starts), writes still work.
        $this->cycle('2026-12-02 06:00:00');
        $this->assertSame(Subscription::PAST_DUE, $this->subscription()->status);
        $this->assertContains('core.subscription.past_due', $this->ownerNotices());
        $this->getJson('/api/v1/subscription/status', $this->headersFor())->assertJsonPath('data.read_only_on', '2026-12-08T06:00:00+00:00');
        $this->aWrite()->assertCreated();

        // Grace (7 days) over: read-only; the POS keeps uploading for 3 days.
        $this->cycle('2026-12-07 23:00:00');
        $this->assertSame(Subscription::PAST_DUE, $this->subscription()->status);
        $this->cycle('2026-12-08 06:00:00');
        $subscription = $this->subscription();
        $this->assertSame([Subscription::READ_ONLY, '2026-12-11 06:00:00'], [$subscription->status, $subscription->pos_stops_at->toDateTimeString()]);
        $this->assertContains('core.subscription.read_only', $this->ownerNotices());

        $this->aWrite()->assertStatus(402)->assertJsonPath('code', 'subscription_overdue');
        // LIM-04: approvals and the user's own account are never refused for dunning.
        $this->assertNotSame(402, $this->postJson('/api/v1/approvals/bulk-approve', [], $this->headersFor())->getStatusCode());
        $this->assertNotSame(402, $this->deleteJson('/api/v1/me/pos-pin', [], $this->headersFor())->getStatusCode());
        $this->getJson('/api/v1/parties', $this->headersFor())->assertOk();
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk();
        $this->assertNotSame(402, $this->posUpload());
        // No new invoice while read-only.
        $this->cycle('2027-01-02 06:00:00');
        $this->assertCount(2, $this->invoices());

        // After the POS window the till is refused with its own message.
        $this->travelToDay('2026-12-11 07:00:00');
        $this->assertSame(402, $this->posUpload());
        $this->postJson('/api/v1/pos/sales', ['sales' => []], ['Authorization' => 'Bearer '.$this->tillToken, 'Accept' => 'application/json'])
            ->assertJsonPath('code', 'subscription_pos_stopped');

        // SUB-06: paying the open invoice restores everything at once.
        $this->payWithMpesa($renewal->id)->assertJsonPath('data.status', 'succeeded');
        $subscription = $this->subscription();
        $this->assertSame([Subscription::ACTIVE, null, null], [$subscription->status, $subscription->read_only_since, $subscription->pos_stops_at]);
        $this->aWrite()->assertCreated();
        $this->assertNotSame(402, $this->posUpload());
    }

    public function test_paying_after_the_period_ended_stays_active_through_the_next_cycles(): void
    {
        $this->activate();
        $this->cycle('2026-12-01 06:00:00');
        $this->cycle('2026-12-02 06:00:00');
        $this->cycle('2026-12-08 06:00:00');
        $this->assertSame(Subscription::READ_ONLY, $this->subscription()->status);

        // Paid only after the next period should have started (2027-01-01): active at once.
        $this->travelToDay('2027-01-05 10:00:00');
        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $this->payWithMpesa($renewal->id)->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);

        // The catch-up invoice for the period that already started falls due now, not on 1 January.
        $this->cycle('2027-01-05 11:00:00');
        $catchUp = collect($this->invoices())->firstWhere('period_start', CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('2027-01-05', $catchUp->due_at->toDateString());
        $this->cycle('2027-01-05 23:00:00');
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);
        // Its own grace period runs from today.
        $this->cycle('2027-01-06 12:00:00');
        $this->assertSame([Subscription::PAST_DUE, '2027-01-05'], [$this->subscription()->status, $this->subscription()->past_due_since->toDateString()]);
    }

    public function test_a_trial_not_paid_by_its_end_is_invoiced_then_past_due(): void
    {
        $this->cycle('2026-11-08 09:00:00');
        $this->assertContains('core.subscription.trial_ending', $this->ownerNotices());

        $this->cycle('2026-11-15 09:00:00');
        $first = $this->invoices()[0];
        $this->assertSame(['first', '2026-11-15', '2026-12-15'], [$first->kind, $first->period_start->toDateString(), $first->period_end->toDateString()]);
        $this->assertSame(Subscription::TRIAL, $this->subscription()->status);

        $this->cycle('2026-11-16 09:00:00');
        $this->assertSame(Subscription::PAST_DUE, $this->subscription()->status);

        // A staff grace override of 2 days makes it read-only sooner.
        $this->inTenant(fn () => $this->subscription()->fill(['grace_days' => 2])->save());
        $this->cycle('2026-11-17 09:00:00');
        $this->assertSame(Subscription::READ_ONLY, $this->subscription()->status);
    }

    public function test_read_only_refuses_every_write_route_except_the_allow_list(): void
    {
        $allowed = [];
        $writes = 0;

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('tenant', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $writes++;
                $class = EnforceSubscription::classify($method, $route);

                if ($class !== EnforceSubscription::BLOCKED) {
                    $allowed["{$method} ".substr($route->uri(), strlen('api/v1/'))] = $class;
                }
            }
        }

        ksort($allowed);
        $this->assertGreaterThan(100, $writes);
        $expected = [
            'POST approvals/bulk-approve' => 'approval',
            'POST approvals/{approval}/approve' => 'approval',
            'POST approvals/{approval}/attachments' => 'approval',
            'POST approvals/{approval}/comment' => 'approval',
            'POST approvals/{approval}/reassign' => 'approval',
            'POST approvals/{approval}/reject' => 'approval',
            'POST approvals/{approval}/request-info' => 'approval',
            'POST approvals/{approval}/return' => 'approval',
            'POST pos/cash-movements/{pos_cash_movement}/approve' => 'approval',
            'POST pos/cash-movements/{pos_cash_movement}/reject' => 'approval',
            'POST pos/refunds/{pos_refund}/approve' => 'approval',
            'POST pos/refunds/{pos_refund}/reject' => 'approval',
            'POST pos/voids/{pos_void}/approve' => 'approval',
            'POST pos/voids/{pos_void}/reject' => 'approval',
            'DELETE auth/sessions/{id}' => 'account',
            'POST users/{user}/sign-out-everywhere' => 'account',
            'DELETE me/two-factor' => 'account',
            'PUT me/pos-pin' => 'account',
            'DELETE me/pos-pin' => 'account',
            'POST auth/sign-out' => 'account',
            'POST me/two-factor/sms' => 'account',
            'POST me/two-factor/sms/confirm' => 'account',
            'POST me/two-factor/totp' => 'account',
            'POST me/two-factor/totp/confirm' => 'account',
            'POST payments/intents' => 'pos_upload',
            'POST pos/cash-movements' => 'pos_upload',
            'POST pos/number-ranges' => 'pos_upload',
            'POST pos/override' => 'device',
            'POST pos/pin/attempts' => 'device',
            'POST pos/pin/change' => 'device',
            'POST pos/pin/verify' => 'device',
            'POST pos/refunds' => 'pos_upload',
            'POST pos/sales' => 'pos_upload',
            'POST pos/shifts' => 'pos_upload',
            'POST pos/voids' => 'pos_upload',
            'POST subscription/change' => 'billing',
            'POST subscription/invoices/{billing_invoice}/pay' => 'billing',
            'POST subscription/payment-methods' => 'billing',
            'POST subscription/payment-methods/card/complete' => 'billing',
            'POST subscription/payment-methods/{billing_payment_method}/archive' => 'billing',
            'POST subscription/payment-methods/{billing_payment_method}/default' => 'billing',
            'POST subscription/pending/cancel' => 'billing',
            'POST subscription/quote' => 'billing',
            'POST subscription/subscribe' => 'billing',
            'POST sync/device-secret/activate' => 'device',
            'POST sync/device-secret/rotate' => 'device',
            'POST tenant-exports' => 'export',
        ];
        ksort($expected);
        $this->assertSame($expected, $allowed);

        // Every allow-listed name is a real route (none left behind after a rename).
        foreach (array_keys(EnforceSubscription::ACCOUNT_WRITES) as $key) {
            $this->assertArrayHasKey($key, $allowed, "{$key} is allow-listed but no such route exists");
        }

        // LIM-04 is absolute: approvals are never blocked, by limits or by dunning.
        $this->assertSame(EnforceSubscription::APPROVAL, EnforceSubscription::classify('POST', 'api/v1/approvals/{approval}/approve'));
        $this->assertSame(EnforceSubscription::APPROVAL, EnforceSubscription::classify('POST', 'api/v1/approvals/bulk-approve'));
    }
}
