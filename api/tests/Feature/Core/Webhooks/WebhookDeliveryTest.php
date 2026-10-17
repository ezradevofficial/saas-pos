<?php

namespace Tests\Feature\Core\Webhooks;

use App\Core\Audit\AuditEntry;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Webhooks\Delivery\WebhookDeliverer;
use App\Core\Webhooks\Delivery\WebhookEndpoints;
use App\Core\Webhooks\Delivery\WebhookFanOut;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\Jobs\FanOutWebhookEvent;
use App\Core\Webhooks\Jobs\SendWebhook;
use App\Core\Webhooks\Jobs\SweepWebhooks;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Models\WebhookSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\POS\PosServiceProvider;
use Tests\Concerns\BuildsWebhooks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Webhooks\ThingHappened;
use Tests\TestCase;

/**
 * HOOK-01 deliveries: an event becomes one signed delivery per matching
 * subscription (Standard Webhooks headers, the event id for deduplication),
 * only for principals who may see it at its scope, without the fields their
 * rules hide; the address is checked again and pinned at every send (SSRF,
 * DNS rebinding, no redirects); failures are retried with back-off, then
 * failed; a subscription failing again and again is switched off and its
 * managers told; deliveries can be replayed and a test event sent.
 */
class WebhookDeliveryTest extends TestCase
{
    use BuildsWebhooks, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->setUpWebhooks();
    }

    public function test_the_signature_matches_the_standard_webhooks_test_vector(): void
    {
        // The Standard Webhooks specification's published example.
        $secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
        $id = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
        $body = '{"test": 2432232314}';

        $this->assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', WebhookSignature::sign($secret, $id, 1614265330, $body));
        $this->assertTrue(WebhookSignature::verify($secret, $id, 1614265330, $body, 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', now: 1614265330 + 60));
        // Too old (a replay), another body, or another id: refused.
        $this->assertFalse(WebhookSignature::verify($secret, $id, 1614265330, $body, 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', now: 1614265330 + 301));
        $this->assertFalse(WebhookSignature::verify($secret, $id, 1614265330, '{"test": 1}', 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', now: 1614265330));
        $this->assertFalse(WebhookSignature::verify($secret, 'msg_other', 1614265330, $body, 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', now: 1614265330));

        // During a rotation both signatures are sent, space separated; either verifies.
        $header = WebhookSignature::header([$secret, 'whsec_'.base64_encode(str_repeat('k', 32))], $id, 1614265330, $body);
        $this->assertStringStartsWith('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE= v1,', $header);
        $this->assertTrue(WebhookSignature::verify('whsec_'.base64_encode(str_repeat('k', 32)), $id, 1614265330, $body, $header, now: 1614265330));
    }

    public function test_an_event_is_delivered_signed_with_its_id_and_documented_data(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response(str_repeat('x', 3000), 200)]);
        $created = $this->subscribe();

        $this->happen();

        $delivery = $this->deliveries()->sole();
        $this->assertSame([WebhookDelivery::DELIVERED, 1, 200], [$delivery->status, $delivery->attempts, $delivery->response_status]);
        $this->assertSame(1024, strlen((string) $delivery->response_body), 'only the first kilobyte is kept');
        $this->assertNotNull($delivery->duration_ms);
        $this->assertSame(1, $this->dns->lookups['hooks.example.com'], 'resolved at send time');

        Http::assertSent(function (Request $request) use ($created, $delivery) {
            $body = $request->body();
            $payload = json_decode($body, true);
            $id = $request->header(WebhookSignature::ID_HEADER)[0];
            $timestamp = (int) $request->header(WebhookSignature::TIMESTAMP_HEADER)[0];

            return $request->url() === 'https://hooks.example.com/in?token=abc'
                && $id === $delivery->event_id && $payload['id'] === $id
                && WebhookSignature::verify($created['secret'], $id, $timestamp, $body, $request->header(WebhookSignature::SIGNATURE_HEADER)[0])
                && $payload['type'] === 'thing.happened.v1'
                && $payload['data'] == [
                    'thing_id' => 'thing-1', 'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id,
                    'location_id' => $this->locationA->id, 'note' => 'a note', 'amount_minor' => '12345',
                ]
                && $request->header('Content-Type')[0] === 'application/json';
        });
        $this->assertNotNull($this->subscription($created['id'])->last_delivery_at);
    }

    public function test_every_subscription_gets_the_same_event_id_and_filters_and_scopes_apply(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $all = $this->subscribe();
        $branchA = $this->subscribe(['branch_id' => $this->branchA->id]);
        $branchB = $this->subscribe(['branch_id' => $this->branchB->id]);
        $company = $this->subscribe(['company_id' => $this->acme->id]);
        // Another event type: never this one.
        $this->subscribe(['events' => ['document.stage_changed.v1']]);

        $this->happen();

        $deliveries = $this->deliveries();
        $this->assertEqualsCanonicalizing([$all['id'], $branchA['id'], $company['id']], $deliveries->pluck('subscription_id')->all());
        $this->assertCount(1, $deliveries->pluck('event_id')->unique(), 'one event id for every receiver');
        $this->assertNotContains($branchB['id'], $deliveries->pluck('subscription_id')->all());
    }

    public function test_a_principal_receives_only_events_at_scopes_they_reach_and_nothing_once_inactive(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // A branch manager of branch A (pos.* there), as the subscription's principal.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $id = $this->inTenant(fn () => WebhookSubscription::create([
            'principal_type' => 'user', 'principal_id' => $manager->id, 'url' => 'https://hooks.example.com/manager', 'url_display' => 'https://hooks.example.com/manager',
            'events' => ['thing.happened.v1'], 'secret' => WebhookSignature::newSecret(), 'created_by' => $this->owner->id,
        ])->id);

        $this->happen(['thingId' => 'at-b', 'branchId' => $this->branchB->id, 'locationId' => $this->locationB->id]);
        $this->assertCount(0, $this->deliveries(), 'branch B is outside the principal\'s scope (RBAC-04)');

        // The branch is completed from the location when the event names only the location.
        $this->happen(['thingId' => 'at-a', 'branchId' => null]);
        $this->assertSame(['at-a'], $this->deliveries()->pluck('payload.data.thing_id')->all());

        $this->inTenant(fn () => User::query()->whereKey($manager->id)->update(['status' => User::STATUS_DEACTIVATED]));
        $this->happen(['thingId' => 'after']);
        $this->assertCount(1, $this->deliveries(), 'an inactive principal receives nothing');
        $this->assertSame($id, $this->deliveries()->sole()->subscription_id);
    }

    public function test_fields_hidden_from_the_principal_are_never_stored_or_sent_or_shown(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $created = $this->subscribe();
        // RBAC-05: the Owner's role hides the note.
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('owner')->id, 'resource' => 'pos_thing', 'field' => 'note', 'mode' => FieldRule::HIDDEN]));

        $this->happen(['note' => 'confidential-note']);

        $delivery = $this->deliveries()->sole();
        $this->assertArrayNotHasKey('note', $delivery->payload['data']);
        $this->assertSame('12345', $delivery->payload['data']['amount_minor']);
        $raw = $this->inTenant(fn () => DB::table('webhook_deliveries')->where('id', $delivery->id)->value('payload'));
        $this->assertStringNotContainsString('confidential-note', $raw);
        Http::assertSent(fn (Request $request) => ! str_contains($request->body(), 'confidential-note'));

        // The log shows the body filtered for its reader as well.
        $this->getJson("/api/v1/webhook-deliveries/{$delivery->id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.payload.data.amount_minor', '12345')
            ->assertJsonMissingPath('data.payload.data.note');
        $this->assertSame($created['id'], $delivery->subscription_id);
    }

    public function test_a_reader_who_may_not_see_the_event_gets_no_body_in_the_log(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->subscribe();
        $this->happen();
        $delivery = $this->deliveries()->sole();
        $admin = $this->userWith('admin', Scope::tenant());

        $this->getJson("/api/v1/webhook-deliveries/{$delivery->id}", $this->headersFor($admin))->assertOk()
            ->assertJsonPath('data.payload', null)
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_nothing_is_queued_for_an_inactive_module_or_a_tenant_without_subscriptions(): void
    {
        Queue::fake();
        $this->subscribe();

        // RBAC-08: the module switched off sends nothing, though the subscription remains.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate(PosServiceProvider::MODULE));
        $this->happen();
        Queue::assertNotPushed(FanOutWebhookEvent::class);

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate(PosServiceProvider::MODULE));
        $this->happen();
        Queue::assertPushed(FanOutWebhookEvent::class, fn (FanOutWebhookEvent $job) => $job->type === 'thing.happened.v1' && $job->tenantId === $this->owner->tenant_id && $job->queue === 'webhooks');
    }

    public function test_whether_a_tenant_has_subscriptions_is_asked_once_per_request_and_not_per_event(): void
    {
        Queue::fake();
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries += str_contains($query->sql, 'webhook_subscriptions') ? 1 : 0;
        });

        $this->inTenant(function () {
            foreach (range(1, 5) as $n) {
                ThingHappened::dispatch($this->owner->tenant_id, "thing-{$n}", $this->acme->id);
            }
        });

        $this->assertSame(1, $queries, 'one memoised query for five events');
        Queue::assertNothingPushed();
    }

    public function test_failures_are_retried_with_back_off_then_failed(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 09:00:00');
        Http::fake(['*' => Http::sequence()->push('down', 503)->pushFailedConnection()->push('busy', 429)->push('moved', 301)->push('no', 404)->push('down', 500)->push('down', 502)]);
        Queue::fake();
        $this->subscribe();
        $this->happen();
        Queue::assertPushed(FanOutWebhookEvent::class, function (FanOutWebhookEvent $job) {
            $this->inTenant(fn () => $job->handle(app(WebhookFanOut::class)));

            return true;
        });
        $delivery = $this->deliveries()->sole();
        $deliverer = app(WebhookDeliverer::class);

        foreach ([60, 300, 1800, 7200, 21600, 86400] as $attempt => $wait) {
            $this->workerStarts($delivery->id);
            $this->inTenant(fn () => $deliverer->deliver($delivery->id));
            $delivery = $delivery->fresh();
            $this->assertSame([WebhookDelivery::RETRYING, $attempt + 1], [$delivery->status, $delivery->attempts]);
            $this->assertTrue(CarbonImmutable::now()->addSeconds($wait)->equalTo($delivery->next_attempt_at), "attempt {$attempt} waits {$wait} s");
            Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $delivery->id && $job->delay === $wait && $job->queue === 'webhooks');
        }

        // The seventh attempt fails for good (the dead-letter state).
        $this->workerStarts($delivery->id);
        $this->inTenant(fn () => $deliverer->deliver($delivery->id));
        $delivery = $delivery->fresh();
        $this->assertSame([WebhookDelivery::FAILED, 7, 502, 'status', null], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->error, $delivery->next_attempt_at]);
        Http::assertSentCount(7);

        // A failed delivery is never sent again by a stray job.
        $this->inTenant(fn () => $deliverer->deliver($delivery->id));
        Http::assertSentCount(7);
        CarbonImmutable::setTestNow();
    }

    public function test_a_redirect_is_never_followed_and_counts_as_a_failure(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('', 302, ['Location' => 'https://10.0.0.1/steal'])]);
        config(['webhooks.backoff' => []]);
        $this->subscribe();

        $this->happen();

        $delivery = $this->deliveries()->sole();
        $this->assertSame([WebhookDelivery::FAILED, 302, 'status'], [$delivery->status, $delivery->response_status, $delivery->error]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '10.0.0.1'));

        // The request options themselves: no redirects, https only, no proxy, the checked address pinned.
        $target = app(WebhookEndpoints::class)->target('https://hooks.example.com/in');
        $options = app(WebhookSender::class)->options($target, 10, 5);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['https'], $options['protocols']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['hooks.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame([10, 5], [$options['timeout'], $options['connect_timeout']]);
    }

    public function test_an_address_that_resolves_to_a_private_network_at_send_time_is_refused_without_a_request(): void
    {
        Http::fake();
        // Saved while it looked public; DNS now answers a private address, then the metadata address (rebinding).
        $this->dns = new FakeHostResolver([
            'rebind.example.com' => [['93.184.216.34'], ['10.0.0.7'], ['169.254.169.254'], ['93.184.216.34', '127.0.0.1']],
        ]);
        $this->app->instance(HostResolver::class, $this->dns);
        Http::fake(['https://rebind.example.com/*' => Http::response('ok', 200)]);
        $this->subscribe(['url' => 'https://rebind.example.com/hook']);

        foreach (['first', 'second', 'third', 'fourth'] as $n) {
            $this->happen(['thingId' => $n]);
        }

        $deliveries = $this->deliveries()->keyBy('payload.data.thing_id');
        $this->assertSame(WebhookDelivery::DELIVERED, $deliveries['first']->status);
        foreach (['second', 'third', 'fourth'] as $n) {
            $this->assertSame([WebhookDelivery::FAILED, 'refused.private_address', 1], [$deliveries[$n]->status, $deliveries[$n]->error, $deliveries[$n]->attempts], $n);
        }
        Http::assertSentCount(1);
        $this->assertSame(4, $this->dns->lookups['rebind.example.com'], 'resolved again for every send');
    }

    public function test_a_subscription_failing_again_and_again_is_switched_off_and_its_managers_are_told(): void
    {
        Http::fake(['*' => Http::sequence()->push('down', 500)->push('ok', 200)->push('down', 500)->push('down', 500)->push('down', 500)]);
        config(['webhooks.backoff' => [], 'webhooks.disable_after' => 2]);
        $admin = $this->userWith('admin', Scope::tenant());
        $created = $this->subscribe();

        $this->happen(['thingId' => 'one']);   // fails
        $this->happen(['thingId' => 'two']);   // delivered: the count starts again
        $this->happen(['thingId' => 'three']); // fails
        $this->assertTrue($this->subscription($created['id'])->active);
        $this->assertSame(1, $this->subscription($created['id'])->consecutive_failures);

        $this->happen(['thingId' => 'four']);  // fails: the second in a row

        $subscription = $this->subscription($created['id']);
        $this->assertSame([false, WebhookSubscription::FAILING, 2], [$subscription->active, $subscription->disabled_reason, $subscription->consecutive_failures]);
        $notified = $this->inTenant(fn () => InAppNotification::query()->where('event_type', WebhookDeliverer::DISABLED_EVENT)->pluck('user_id')->all());
        $this->assertEqualsCanonicalizing([$this->owner->id, $admin->id], $notified);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.webhook.disable')->count()));

        // Switched off: nothing more is queued or sent.
        $this->happen(['thingId' => 'five']);
        $this->assertCount(4, $this->deliveries());
        Http::assertSentCount(4);
    }

    public function test_test_events_are_sent_once_and_never_count_against_the_subscription(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        config(['webhooks.disable_after' => 1]);
        $created = $this->subscribe(['events' => ['document.stage_changed.v1']]);

        $response = $this->postJson("/api/v1/webhooks/{$created['id']}/test", [], $this->headersFor())->assertStatus(202)
            ->assertJsonPath('data.event_type', 'webhook.test.v1');

        $delivery = $this->deliveries()->sole();
        $this->assertSame([WebhookDelivery::FAILED, 1, 500], [$delivery->status, $delivery->attempts, $delivery->response_status]);
        $this->assertSame($response->json('data.id'), $delivery->id);
        $this->assertTrue($this->subscription($created['id'])->active);
        $this->assertSame(0, $this->subscription($created['id'])->consecutive_failures);
        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['type'] === 'webhook.test.v1'
            && json_decode($request->body(), true)['data']['webhook_id'] === $created['id']);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.webhook.test')->count()));
    }

    public function test_a_delivery_is_replayed_with_the_same_event_id_and_body(): void
    {
        Http::fake(['*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
        config(['webhooks.backoff' => []]);
        $created = $this->subscribe();
        $this->happen();
        $failed = $this->deliveries()->sole();
        $this->assertSame(WebhookDelivery::FAILED, $failed->status);

        $replay = $this->postJson("/api/v1/webhook-deliveries/{$failed->id}/replay", [], $this->headersFor())->assertStatus(202)
            ->assertJsonPath('data.replay_of', $failed->id)
            ->assertJsonPath('data.event_id', $failed->event_id);

        $sent = $this->deliveries()->firstWhere('id', $replay->json('data.id'));
        $this->assertSame([WebhookDelivery::DELIVERED, $failed->event_id, $this->owner->id], [$sent->status, $sent->event_id, $sent->requested_by]);
        $this->assertEquals($failed->payload, $sent->payload);
        $bodies = [];
        Http::assertSent(function (Request $request) use (&$bodies) {
            $bodies[] = [$request->header(WebhookSignature::ID_HEADER)[0], $request->body()];

            return true;
        });
        $this->assertSame($bodies[0], $bodies[1], 'the same id and body');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.webhook.replay')->count()));

        // Not while the subscription is off.
        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['active' => false], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/webhook-deliveries/{$failed->id}/replay", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'webhook_inactive');
    }

    public function test_the_delivery_log_lists_and_filters(): void
    {
        Http::fake(['*' => Http::sequence()->push('ok', 200)->push('down', 500)]);
        config(['webhooks.backoff' => []]);
        $first = $this->subscribe();
        $second = $this->subscribe(['url' => 'https://other.example.com/…/••••']);
        $this->happen();

        $this->getJson('/api/v1/webhook-deliveries', $this->headersFor())->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/webhook-deliveries?webhook={$second['id']}", $this->headersFor())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.webhook.url_display', 'https://other.example.com/…/••••');
        $this->getJson('/api/v1/webhook-deliveries?status=failed', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/webhook-deliveries?type=webhook.test.v1', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/webhook-deliveries?status=nonsense', $this->headersFor())->assertUnprocessable();
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_the_sweep_prunes_old_deliveries_and_requeues_ones_left_behind(): void
    {
        $created = $this->subscribe();
        $make = fn (string $status, string $createdAt, array $extra = []) => $this->inTenant(function () use ($created, $status, $createdAt, $extra) {
            $delivery = WebhookDelivery::create(['subscription_id' => $created['id'], 'event_id' => (string) Str::uuid7(), 'event_type' => 'thing.happened.v1', 'payload' => ['data' => []], 'status' => $status, ...$extra]);
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

            return $delivery->id;
        });
        $old = $make('delivered', now()->subDays(31)->toIso8601String());
        $recent = $make('delivered', now()->subDays(29)->toIso8601String());
        $stuck = $make('sending', now()->subMinutes(20)->toIso8601String());
        $overdue = $make('retrying', now()->subHours(1)->toIso8601String(), ['next_attempt_at' => now()->subMinutes(30)]);
        $waiting = $make('retrying', now()->subMinutes(1)->toIso8601String(), ['next_attempt_at' => now()->addMinutes(30)]);
        Queue::fake();

        $this->artisan('webhooks:sweep')->assertSuccessful();
        Queue::assertPushed(SweepWebhooks::class, function ($job) {
            $this->inTenant(fn () => $job->handle());

            return $job->tenantId === $this->owner->tenant_id;
        });

        $ids = $this->deliveries()->pluck('id')->all();
        $this->assertNotContains($old, $ids, 'older than 30 days: deleted');
        $this->assertContains($recent, $ids);
        $pushed = [];
        Queue::assertPushed(SendWebhook::class, function (SendWebhook $job) use (&$pushed) {
            $pushed[] = $job->deliveryId;

            return true;
        });
        $this->assertEqualsCanonicalizing([$stuck, $overdue], $pushed);
        $this->assertSame(WebhookDelivery::RETRYING, $this->deliveries()->firstWhere('id', $stuck)->status);
        $this->assertContains($waiting, $ids);

        // Nothing to do for a tenant whose log is clean.
        $this->inTenant(fn () => WebhookDelivery::query()->delete());
        Queue::fake();
        $this->artisan('webhooks:sweep')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_no_answer_is_retried_and_its_duration_kept(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        config(['webhooks.backoff' => []]);
        $this->subscribe();

        $this->happen();

        $delivery = $this->deliveries()->sole();
        $this->assertSame([WebhookDelivery::FAILED, 'unreachable', null], [$delivery->status, $delivery->error, $delivery->response_status]);
        $this->assertNotNull($delivery->duration_ms);
    }

    /** A worker picks the queued SendWebhook up: its unique lock is released (ShouldBeUniqueUntilProcessing). */
    private function workerStarts(string $deliveryId): void
    {
        (new UniqueLock(app(Cache::class)))->release(new SendWebhook($this->owner->tenant_id, $deliveryId));
    }
}
