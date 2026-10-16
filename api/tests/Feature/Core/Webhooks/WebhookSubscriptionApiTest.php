<?php

namespace Tests\Feature\Core\Webhooks;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Models\WebhookSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\InventoryServiceProvider;
use Tests\Concerns\BuildsWebhooks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * HOOK-01: Settings → Webhooks through the API. Every route needs
 * `core.webhook.manage` at tenant scope (Owner, Admin); another tenant's
 * subscription is not found. The URL passes the SSRF rules on save; the
 * secret is shown once (create, rotate), never listed, never audited;
 * events are only types of active modules the user may see; the filter is
 * a company or a branch of this tenant.
 */
class WebhookSubscriptionApiTest extends TestCase
{
    use BuildsWebhooks, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->setUpWebhooks();
    }

    public function test_the_owner_creates_a_subscription_and_sees_the_secret_once(): void
    {
        $response = $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.com/in?token=abc',
            'description' => 'Sales to the warehouse system',
            'events' => ['thing.happened.v1'],
            'branch_id' => $this->branchA->id,
        ], $this->headersFor())->assertCreated()
            ->assertJsonPath('data.url_display', 'https://hooks.example.com/in')
            ->assertJsonPath('data.events', ['thing.happened.v1'])
            ->assertJsonPath('data.branch_id', $this->branchA->id)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.principal.type', 'user')
            ->assertJsonPath('data.principal.id', $this->owner->id);

        $secret = $response->json('data.secret');
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/', $secret);
        $id = $response->json('data.id');

        // Never again: not in the list, not in one subscription, not stored in plain text.
        foreach (["/api/v1/webhooks/{$id}", '/api/v1/webhooks'] as $uri) {
            $body = $this->getJson($uri, $this->headersFor())->assertOk()->getContent();
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString('token=abc', $body);
            $this->assertStringNotContainsString('"secret"', $body);
        }
        $raw = $this->inTenant(fn () => (array) \DB::table('webhook_subscriptions')->where('id', $id)->first());
        $this->assertStringNotContainsString($secret, json_encode($raw));
        $this->assertStringNotContainsString('token=abc', json_encode($raw));
        $this->assertSame($secret, $this->subscription($id)->secret);

        // AUD-01: audited without the URL's query or the secret.
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.webhook.create')->sole());
        $this->assertSame('https://hooks.example.com/in', $entry->after['url']);
        $this->assertStringNotContainsString($secret, json_encode($entry->toArray()));
        $this->assertStringNotContainsString('token=abc', json_encode($entry->toArray()));
    }

    public function test_the_admin_manages_webhooks_and_other_roles_are_refused_on_every_route(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        // The Admin template reads no POS sales: it subscribes to what it may see.
        $created = $this->subscribe(['events' => ['document.stage_changed.v1']], $admin);
        $this->assertNotEmpty($created['secret']);

        $managerAtBranch = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $adminAtCompany = $this->userWith('admin', Scope::company($this->acme->id));
        $delivery = $this->inTenant(function () use ($created) {
            return WebhookDelivery::create([
                'subscription_id' => $created['id'], 'event_id' => (string) Str::uuid7(), 'event_type' => 'thing.happened.v1',
                'payload' => ['id' => 'x', 'type' => 'thing.happened.v1', 'occurred_at' => now()->toIso8601ZuluString(), 'data' => ['thing_id' => 'thing-1']],
                'status' => 'delivered',
            ])->id;
        });

        $routes = [
            ['GET', '/api/v1/webhooks/catalogue'],
            ['GET', '/api/v1/webhooks'],
            ['POST', '/api/v1/webhooks'],
            ['GET', "/api/v1/webhooks/{$created['id']}"],
            ['PATCH', "/api/v1/webhooks/{$created['id']}"],
            ['POST', "/api/v1/webhooks/{$created['id']}/rotate-secret"],
            ['POST', "/api/v1/webhooks/{$created['id']}/test"],
            ['POST', "/api/v1/webhooks/{$created['id']}/archive"],
            ['GET', '/api/v1/webhook-deliveries'],
            ['GET', "/api/v1/webhook-deliveries/{$delivery}"],
            ['POST', "/api/v1/webhook-deliveries/{$delivery}/replay"],
        ];

        // A branch manager and an admin of one company only hold no tenant-wide webhook permission.
        foreach ([$managerAtBranch, $adminAtCompany] as $user) {
            foreach ($routes as [$method, $uri]) {
                $this->json($method, $uri, ['url' => 'https://hooks.example.com/x', 'events' => ['thing.happened.v1']], $this->headersFor($user))
                    ->assertForbidden();
            }
        }

        // Nothing changed for them.
        $this->assertTrue($this->subscription($created['id'])->active);
        $this->assertNull($this->subscription($created['id'])->archived_at);

        $this->getJson('/api/v1/webhooks', $this->headersFor($admin))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/webhooks/catalogue', $this->headersFor($admin))->assertOk();
    }

    public function test_another_tenants_subscriptions_and_deliveries_are_not_found(): void
    {
        $other = $this->otherTenant();
        $theirs = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            $subscription = WebhookSubscription::create([
                'principal_type' => 'user', 'principal_id' => $other['user']->id, 'url' => 'https://other.example.com/hook', 'url_display' => 'https://other.example.com/hook',
                'events' => ['thing.happened.v1'], 'secret' => WebhookSignature::newSecret(), 'created_by' => $other['user']->id,
            ]);
            $delivery = WebhookDelivery::create([
                'subscription_id' => $subscription->id, 'event_id' => (string) Str::uuid7(), 'event_type' => 'thing.happened.v1',
                'payload' => ['data' => []], 'status' => 'failed',
            ]);

            return [$subscription->id, $delivery->id];
        });
        [$subscription, $delivery] = $theirs;

        foreach ([
            ['GET', "/api/v1/webhooks/{$subscription}"],
            ['PATCH', "/api/v1/webhooks/{$subscription}"],
            ['POST', "/api/v1/webhooks/{$subscription}/rotate-secret"],
            ['POST', "/api/v1/webhooks/{$subscription}/test"],
            ['POST', "/api/v1/webhooks/{$subscription}/archive"],
            ['GET', "/api/v1/webhook-deliveries/{$delivery}"],
            ['POST', "/api/v1/webhook-deliveries/{$delivery}/replay"],
        ] as [$method, $uri]) {
            $this->json($method, $uri, ['description' => 'hijack'], $this->headersFor())->assertNotFound();
        }

        $this->getJson('/api/v1/webhooks', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/webhook-deliveries', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/webhook-deliveries?webhook={$subscription}", $this->headersFor())->assertUnprocessable();

        // A filter naming the other tenant's company or branch is refused.
        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1'], 'company_id' => $other['company']->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1'], 'branch_id' => $other['branch']->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->asTenant($other['user']->tenant_id, fn () => $this->assertSame('https://other.example.com/hook', WebhookSubscription::query()->findOrFail($subscription)->url_display));
    }

    public function test_urls_must_be_public_https_without_credentials(): void
    {
        foreach ([
            'http://hooks.example.com/in' => 'not_https',
            'https://user:pass@hooks.example.com/in' => 'credentials',
            'https://127.0.0.1/in' => 'private_address',
            'https://10.0.0.7/in' => 'private_address',
            'https://169.254.169.254/latest/meta-data' => 'private_address',
            'https://[::1]/in' => 'private_address',
            'https://2130706433/in' => 'invalid',
            'https://localhost/in' => 'invalid',
            'ftp://hooks.example.com' => 'not_https',
        ] as $url => $reason) {
            $this->postJson('/api/v1/webhooks', ['url' => $url, 'events' => ['thing.happened.v1']], $this->headersFor())
                ->assertUnprocessable()
                ->assertJsonPath('errors.url.0', __('webhooks.url.'.$reason));
        }

        // Local development hosts are never honoured outside APP_ENV=local.
        config(['webhooks.local_hosts' => ['127.0.0.1']]);
        $this->postJson('/api/v1/webhooks', ['url' => 'http://127.0.0.1:9000/in', 'events' => ['thing.happened.v1']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->assertSame(0, $this->inTenant(fn () => WebhookSubscription::query()->count()));
    }

    public function test_events_must_be_available_types_the_user_may_see(): void
    {
        // Unknown, the test type, and a type of an inactive module (RBAC-08).
        $this->assertFalse($this->inTenant(fn () => app(ModuleRegistry::class)->isActive(InventoryServiceProvider::MODULE)));
        foreach (['nothing.happened.v1', 'webhook.test.v1', 'stock.low.v1'] as $event) {
            $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => [$event]], $this->headersFor())
                ->assertUnprocessable()->assertJsonValidationErrors('events.0');
        }

        $keys = array_column($this->getJson('/api/v1/webhooks/catalogue', $this->headersFor())->assertOk()->json('data'), 'key');
        $this->assertContains('thing.happened.v1', $keys);
        $this->assertContains('sale.completed.v1', $keys);
        $this->assertContains('document.stage_changed.v1', $keys);
        $this->assertNotContains('stock.low.v1', $keys);
        $this->assertNotContains('payroll.approved.v1', $keys);
        $this->assertNotContains('webhook.test.v1', $keys);

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate(InventoryServiceProvider::MODULE));
        $keys = array_column($this->getJson('/api/v1/webhooks/catalogue', $this->headersFor())->json('data'), 'key');
        $this->assertContains('stock.low.v1', $keys);
        $this->subscribe(['events' => ['stock.low.v1']]);
    }

    public function test_an_admin_without_a_permission_of_an_event_cannot_subscribe_to_it(): void
    {
        // A copy of the Admin role without POS sales (RBAC-02): manages webhooks, cannot see sales.
        $user = $this->inTenant(function () {
            $role = $this->role('Webhook admin', ['core.webhook.manage']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });

        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1']], $this->headersFor($user))
            ->assertUnprocessable()->assertJsonPath('errors', ['events.0' => [__('webhooks.errors.event_not_visible')]]);
        // document.stage_changed.v1 is checked per document when it happens.
        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['document.stage_changed.v1']], $this->headersFor($user))
            ->assertCreated();
    }

    public function test_update_changes_the_url_events_filter_and_active_flag_and_is_audited(): void
    {
        $created = $this->subscribe(['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/webhooks/{$created['id']}", [
            'url' => 'https://other.example.com/hook?key=new',
            'branch_id' => $this->branchB->id,
            'description' => 'Changed',
            'active' => false,
        ], $this->headersFor())->assertOk()
            ->assertJsonPath('data.url_display', 'https://other.example.com/hook')
            ->assertJsonPath('data.branch_id', $this->branchB->id)
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.active', false);

        $subscription = $this->subscription($created['id']);
        $this->assertSame('https://other.example.com/hook?key=new', $subscription->url);
        $this->assertSame($created['secret'], $subscription->secret, 'changing the URL keeps the secret');

        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.webhook.update')->sole());
        $this->assertTrue($entry->after['url_changed']);
        $this->assertStringNotContainsString('key=new', json_encode($entry->toArray()));

        // Both filters at once are refused.
        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id], $this->headersFor())
            ->assertUnprocessable();
        // A private URL is refused on update too.
        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['url' => 'https://192.168.1.10/hook'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_switching_a_failing_subscription_back_on_resets_its_failure_count(): void
    {
        $created = $this->subscribe();
        $this->inTenant(fn () => WebhookSubscription::query()->whereKey($created['id'])->update([
            'active' => false, 'consecutive_failures' => 10, 'disabled_reason' => 'failing', 'disabled_at' => now(),
        ]));
        $this->getJson("/api/v1/webhooks/{$created['id']}", $this->headersFor())->assertJsonPath('data.disabled_reason', 'failing');

        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['active' => true], $this->headersFor())->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.consecutive_failures', 0)
            ->assertJsonPath('data.disabled_reason', null);
    }

    public function test_rotating_the_secret_shows_the_new_one_once_and_keeps_the_old_one_signing_for_the_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 09:00:00');
        $created = $this->subscribe();

        $rotated = $this->postJson("/api/v1/webhooks/{$created['id']}/rotate-secret", [], $this->headersFor())->assertOk();
        $new = $rotated->json('data.secret');
        $this->assertNotSame($created['secret'], $new);
        $this->assertSame('2026-10-17T09:00:00+00:00', $rotated->json('data.previous_secret_expires_at'));

        $subscription = $this->subscription($created['id']);
        $this->assertSame([$new, $created['secret']], $subscription->signingSecrets());
        $this->assertSame([$new], $subscription->signingSecrets(CarbonImmutable::parse('2026-10-17 09:00:01')));
        $this->assertStringNotContainsString($new, $this->getJson("/api/v1/webhooks/{$created['id']}", $this->headersFor())->getContent());
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.webhook.rotate_secret')->count()));
        CarbonImmutable::setTestNow();
    }

    public function test_archiving_stops_deliveries_and_hides_the_subscription(): void
    {
        Queue::fake();
        $created = $this->subscribe();

        $this->postJson("/api/v1/webhooks/{$created['id']}/archive", [], $this->headersFor())->assertOk();

        $this->getJson('/api/v1/webhooks', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/webhooks/{$created['id']}", $this->headersFor())->assertNotFound();
        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['active' => true], $this->headersFor())->assertNotFound();
        $this->assertNotNull($this->subscription($created['id'])->archived_at, 'archived, never deleted (TEN-06)');

        $this->happen();
        Queue::assertNothingPushed();
    }

    public function test_the_number_of_subscriptions_is_limited(): void
    {
        config(['webhooks.max_subscriptions' => 2]);
        $this->subscribe();
        $this->subscribe();

        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1']], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'too_many_webhooks');
    }
}
