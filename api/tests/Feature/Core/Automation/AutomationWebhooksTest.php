<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Models\WebhookDelivery;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Automation\Webhooks\Signature;
use App\Core\Notifications\Models\InAppNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\FakeHostResolver;
use Tests\TestCase;

/**
 * AUTO-03 webhooks through the outbox: the run writes a delivery in its
 * transaction and never waits on HTTP; once committed the delivery is sent,
 * signed, with `X-Webhook-Id` = run id:action index and the committed
 * document; it has its own retries (no answer, 5xx, 408, 429) and alerts
 * the administrators when it fails for good. A run that rolls back sends
 * nothing; a refused address or a 4xx fails the delivery without HTTP
 * retries; the run itself is never repeated for a webhook.
 */
class AutomationWebhooksTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    private FakeHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->setUpAutomation();
        $this->dns = new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']], 'intranet.example.com' => [['10.0.0.7']]]);
        $this->app->instance(HostResolver::class, $this->dns);
    }

    private function delivery(): WebhookDelivery
    {
        return $this->inTenant(fn () => WebhookDelivery::query()->sole());
    }

    private function alerts(): int
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'core.automation.failed')->count());
    }

    public function test_a_delivery_is_signed_and_carries_the_committed_document(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response(str_repeat('x', 3000), 200)]);
        $rule = $this->saveRule(['type' => 'record_created'], [
            ['type' => 'update_field', 'field' => 'urgent', 'value' => true],
            ['type' => 'webhook', 'url' => 'https://hooks.example.com/in?token=abc'],
        ]);
        $secret = $rule->revealedSecret;

        $id = $this->createTask(['amount' => $this->kes(1000)]);

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $delivery = $this->delivery();
        $this->assertEquals(['delivery_id' => $delivery->id, 'url' => 'https://hooks.example.com/in'], $run->actions[1]['result']);
        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame(1024, strlen($delivery->response_body), 'only the first kilobyte is kept');
        $this->assertSame(1, $this->dns->lookups['hooks.example.com']);

        Http::assertSent(function (Request $request) use ($secret, $run, $id, $rule) {
            $body = $request->body();
            $payload = json_decode($body, true);

            return $request->url() === 'https://hooks.example.com/in?token=abc'
                && $request->header(Signature::ID_HEADER)[0] === $run->id.':1'
                && $payload['id'] === $run->id.':1'
                && Signature::verify($secret, (int) $request->header(Signature::TIMESTAMP_HEADER)[0], $body, $request->header(Signature::SIGNATURE_HEADER)[0])
                && $payload['rule'] == ['id' => $rule->id, 'name' => $rule->name, 'version' => 1]
                && $payload['document'] == ['type' => 'core.test_task', 'id' => $id]
                && $payload['fields']['amount'] == $this->kes(1000)
                // The committed state: the earlier action's change is in it.
                && $payload['fields']['urgent'] === true;
        });
    }

    public function test_a_run_that_rolls_back_sends_nothing(): void
    {
        Http::fake();
        $rule = $this->saveRule(['type' => 'record_created'], [
            ['type' => 'webhook', 'url' => 'https://hooks.example.com/in'],
            ['type' => 'change_stage', 'mode' => 'move'], // no workflow: fails
        ]);

        $this->createTask();

        $this->assertSame(AutomationRun::FAILED, $this->runs($rule)->sole()->outcome);
        $this->assertSame(['rolled_back', 'failed'], array_column($this->runs($rule)->sole()->actions, 'status'));
        $this->assertSame(0, $this->inTenant(fn () => WebhookDelivery::query()->count()));
        Http::assertNothingSent();
    }

    public function test_a_private_address_fails_the_delivery_without_any_request_and_alerts(): void
    {
        Http::fake();
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'webhook', 'url' => 'https://intranet.example.com/hook']]);

        $this->createTask();

        $this->assertSame(AutomationRun::SUCCEEDED, $this->runs($rule)->sole()->outcome, 'the run committed; the delivery failed');
        $delivery = $this->delivery();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('private or internal network', $delivery->error);
        Http::assertNothingSent();
        $this->assertSame(1, $this->alerts());
    }

    public function test_a_4xx_answer_fails_without_retrying(): void
    {
        Http::fake(['*' => Http::response('nope', 404)]);
        $this->saveRule(['type' => 'record_created'], [['type' => 'webhook', 'url' => 'https://hooks.example.com/in']]);

        $this->createTask();

        $delivery = $this->delivery();
        $this->assertSame([WebhookDelivery::FAILED, 1, 404, 'The webhook answered 404.'], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->error]);
        Http::assertSentCount(1);
    }

    public function test_5xx_and_no_answer_are_retried_by_the_delivery_not_the_run(): void
    {
        Http::fakeSequence('hooks.example.com/*')->push('down', 503)->pushFailedConnection()->push('ok', 202);
        $rule = $this->saveRule(['type' => 'record_created'], [
            ['type' => 'update_field', 'field' => 'quantity', 'value' => '7'],
            ['type' => 'webhook', 'url' => 'https://hooks.example.com/in'],
        ]);

        $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame([AutomationRun::SUCCEEDED, 1], [$run->outcome, $run->attempts], 'the run ran once');
        $delivery = $this->delivery();
        $this->assertSame([WebhookDelivery::DELIVERED, 3, 202], [$delivery->status, $delivery->attempts, $delivery->response_status]);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->header(Signature::ID_HEADER)[0] === $run->id.':1');
        $this->assertSame(0, $this->alerts());
    }

    public function test_a_delivery_failing_every_attempt_alerts_the_administrators(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $this->saveRule(['type' => 'record_created'], [['type' => 'webhook', 'url' => 'https://hooks.example.com/in']]);

        $this->createTask();

        $delivery = $this->delivery();
        $this->assertSame([WebhookDelivery::FAILED, 3], [$delivery->status, $delivery->attempts]);
        $this->assertStringContainsString('couldn’t be reached', $delivery->error);
        $this->assertSame(1, $this->alerts());
    }
}
