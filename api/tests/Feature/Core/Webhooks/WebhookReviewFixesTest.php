<?php

namespace Tests\Feature\Core\Webhooks;

use App\Core\Audit\AuditEntry;
use App\Core\DataExport\Models\TenantExport;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use App\Core\Webhooks\Delivery\WebhookDeliverer;
use App\Core\Webhooks\Delivery\WebhookDispatcher;
use App\Core\Webhooks\Delivery\WebhookFanOut;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\Jobs\FanOutWebhookEvent;
use App\Core\Webhooks\Jobs\SendWebhook;
use App\Core\Webhooks\Jobs\SweepWebhooks;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\WebhookPrincipal;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\PosServiceProvider;
use Tests\Concerns\BuildsWebhooks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * HOOK-01 review findings: ownership of a subscription and take-over,
 * deliveries checked again where the event happened (sending, replay, the
 * log), masked URLs, the sweep's indexes and marks, one delivery per
 * subscription and event, the subscription cap's lock, ids-only queue
 * payloads and the event's own time.
 */
class WebhookReviewFixesTest extends TestCase
{
    use BuildsWebhooks, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->setUpWebhooks();
    }

    /** A colleague holding $permissions through a role of their own, at $scope. */
    private function holder(array $permissions, Scope $scope): User
    {
        return $this->inTenant(function () use ($permissions, $scope) {
            $role = $this->role('Role '.Str::random(6), $permissions);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, $scope);

            return $user;
        });
    }

    // ---- Finding 1: ownership -------------------------------------------

    public function test_only_the_principal_changes_the_url_events_or_filter_others_may_rename_or_switch_off(): void
    {
        $created = $this->subscribe(['company_id' => $this->acme->id]);
        $admin = $this->holder(['core.webhook.manage', 'pos.sale.view'], Scope::tenant());

        foreach ([
            ['url' => 'https://other.example.com/steal'],
            ['events' => ['thing.happened.v1', 'sale.completed.v1']],
            ['company_id' => null],
            ['branch_id' => $this->branchA->id],
        ] as $change) {
            $this->patchJson("/api/v1/webhooks/{$created['id']}", $change, $this->headersFor($admin))
                ->assertForbidden()->assertJsonPath('code', 'webhook_not_owner');
        }
        $this->assertSame('https://hooks.example.com/in?token=abc', $this->subscription($created['id'])->url);

        // The edit form sends the unchanged events and filter with the description: allowed.
        $this->patchJson("/api/v1/webhooks/{$created['id']}", [
            'description' => 'Renamed', 'events' => ['thing.happened.v1'], 'company_id' => $this->acme->id, 'branch_id' => null, 'active' => false,
        ], $this->headersFor($admin))->assertOk()->assertJsonPath('data.description', 'Renamed')->assertJsonPath('data.active', false)
            ->assertJsonPath('data.principal.is_me', false);
    }

    public function test_the_owner_takes_over_a_subscription_and_then_changes_it(): void
    {
        $admin = $this->holder(['core.webhook.manage', 'pos.sale.view'], Scope::tenant());
        $created = $this->subscribe([], $admin);

        // Only an Owner takes over.
        $other = $this->holder(['core.webhook.manage', 'pos.sale.view'], Scope::tenant());
        $this->postJson("/api/v1/webhooks/{$created['id']}/take-over", [], $this->headersFor($other))->assertForbidden();

        $this->postJson("/api/v1/webhooks/{$created['id']}/take-over", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.principal.id', $this->owner->id)
            ->assertJsonPath('data.principal.is_me', true);
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.webhook.take_over')->sole());
        $this->assertSame($admin->id, $entry->before['principal_id']);
        $this->assertSame($this->owner->id, $entry->after['principal_id']);

        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['url' => 'https://other.example.com/new'], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/webhooks/{$created['id']}", ['url' => 'https://other.example.com/back'], $this->headersFor($admin))
            ->assertForbidden()->assertJsonPath('code', 'webhook_not_owner');
    }

    public function test_events_are_checked_against_the_principal_at_the_subscriptions_scope(): void
    {
        // Webhooks for the tenant, sales at branch A only.
        $user = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Webhooks', ['core.webhook.manage']), Scope::tenant());
            $this->assign($user, $this->role('Branch A sales', ['pos.sale.view']), Scope::branch($this->branchA->id));

            return $user;
        });

        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1']], $this->headersFor($user))
            ->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1'], 'branch_id' => $this->branchB->id], $this->headersFor($user))
            ->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $id = $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['thing.happened.v1'], 'branch_id' => $this->branchA->id], $this->headersFor($user))
            ->assertCreated()->json('data.id');

        // Widening the filter to the whole tenant is refused for that principal.
        $this->patchJson("/api/v1/webhooks/{$id}", ['branch_id' => null], $this->headersFor($user))
            ->assertUnprocessable()->assertJsonValidationErrors('events.0');
    }

    public function test_a_replay_goes_only_while_the_current_principal_still_sees_the_event(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        config(['webhooks.backoff' => []]);
        $admin = $this->holder(['core.webhook.manage', 'pos.sale.view'], Scope::tenant());
        $this->subscribe([], $admin);
        $this->happen();
        $failed = $this->deliveries()->sole();

        // The principal loses the sales permission: no replay, though the Owner (who sees it) asks.
        $this->inTenant(fn () => RoleAssignment::query()->where('user_id', $admin->id)->delete());
        $this->postJson("/api/v1/webhook-deliveries/{$failed->id}/replay", [], $this->headersFor())
            ->assertStatus(409)->assertJsonPath('code', 'not_visible');
        $this->assertCount(1, $this->deliveries());
    }

    // ---- Finding 2: the log's bodies ------------------------------------

    public function test_the_log_checks_each_reader_against_where_the_event_happened_for_types_without_a_permission(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // A type checked per principal (as document stage changes are): only the Owner may see it.
        $ownerId = $this->owner->id;
        app(WebhookCatalogue::class)->register(new WebhookEventType(
            key: 'private.happened.v1', module: ModuleRegistry::CORE, event: null, permission: null,
            fields: ['secret_note'], authorise: fn (WebhookPrincipal $principal) => $principal->id() === $ownerId,
        ));
        $subscription = $this->subscription($this->subscribe(['events' => ['document.stage_changed.v1']])['id']);
        $delivery = $this->inTenant(fn () => app(WebhookFanOut::class)->single($subscription, (string) Str::uuid7(), 'private.happened.v1',
            WebhookFanOut::envelope('e', 'private.happened.v1', now()->toIso8601ZuluString(), ['secret_note' => 'only-the-owner']), null, null, ['company_id' => $this->acme->id]));
        $manager = $this->holder(['core.webhook.manage'], Scope::tenant());

        $this->getJson("/api/v1/webhook-deliveries/{$delivery->id}", $this->headersFor())->assertOk()->assertJsonPath('data.payload.data.secret_note', 'only-the-owner');
        $hidden = $this->getJson("/api/v1/webhook-deliveries/{$delivery->id}", $this->headersFor($manager))->assertOk()->assertJsonPath('data.payload', null);
        $this->assertStringNotContainsString('only-the-owner', $hidden->getContent());
    }

    public function test_the_log_checks_the_reader_at_the_events_scope(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->subscribe();
        $this->happen(['branchId' => $this->branchB->id, 'locationId' => $this->locationB->id, 'note' => 'branch-b-note']);
        $delivery = $this->deliveries()->sole();
        // Manages webhooks, sees sales at branch A only: the branch B event's body stays hidden.
        $reader = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Webhooks', ['core.webhook.manage']), Scope::tenant());
            $this->assign($user, $this->role('Branch A sales', ['pos.sale.view']), Scope::branch($this->branchA->id));

            return $user;
        });

        $response = $this->getJson("/api/v1/webhook-deliveries/{$delivery->id}", $this->headersFor($reader))->assertOk()->assertJsonPath('data.payload', null);
        $this->assertStringNotContainsString('branch-b-note', $response->getContent());
    }

    // ---- Finding 3: sending ---------------------------------------------

    public function test_at_send_time_the_module_the_principal_and_field_rules_are_checked_again(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        Queue::fake([SendWebhook::class]);
        $this->subscribe();
        $this->happen(['thingId' => 'one', 'note' => 'late-hidden-note']);
        $this->happen(['thingId' => 'two']);
        $this->assertCount(2, $this->deliveries(), 'both events fanned out');
        [$one, $two] = [$this->deliveries()->first(fn ($d) => $d->payload['data']['thing_id'] === 'one'), $this->deliveries()->first(fn ($d) => $d->payload['data']['thing_id'] === 'two')];
        $deliverer = app(WebhookDeliverer::class);

        // A field rule added after the fan-out still applies to what is sent.
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('owner')->id, 'resource' => 'pos_thing', 'field' => 'note', 'mode' => FieldRule::HIDDEN]));
        // The send runs in a job of its own (memos start afresh, WebhooksServiceProvider).
        app(WebhookPrincipals::class)->flush();
        $this->inTenant(fn () => $deliverer->deliver($one->id));
        $this->assertSame([WebhookDelivery::DELIVERED, null], [$one->fresh()->status, $one->fresh()->error]);
        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['data']['thing_id'] === 'one' && ! str_contains($request->body(), 'late-hidden-note'));
        $this->assertStringNotContainsString('late-hidden-note', (string) $this->inTenant(fn () => DB::table('webhook_deliveries')->where('id', $one->id)->value('payload')));

        // The module switched off before the send: failed as principal, nothing sent.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate(PosServiceProvider::MODULE));
        $this->inTenant(fn () => $deliverer->deliver($two->id));
        $this->assertSame([WebhookDelivery::FAILED, 'principal'], [$two->fresh()->status, $two->fresh()->error]);
        Http::assertSentCount(1);
    }

    // ---- Finding 4: masked URLs -----------------------------------------

    public function test_a_path_token_never_reaches_the_audit_log_a_notification_or_the_export(): void
    {
        Storage::fake('media');
        Http::fake(['*' => Http::response('down', 500)]);
        config(['webhooks.backoff' => [], 'webhooks.disable_after' => 1]);
        $created = $this->subscribe(['url' => 'https://hooks.example.com/services/path-token-7f3a/in?key=query-token-9c1e']);
        $this->getJson("/api/v1/webhooks/{$created['id']}", $this->headersFor())->assertJsonPath('data.url_display', 'https://hooks.example.com/…/••••');
        $this->happen(); // fails, and switches the webhook off (notification)

        $this->inTenant(function () {
            $this->assertGreaterThan(0, InAppNotification::query()->where('event_type', WebhookDeliverer::DISABLED_EVENT)->count());
            $notifications = InAppNotification::query()->get()->toJson();
            $audit = AuditEntry::query()->get()->toJson();

            foreach (['path-token-7f3a', 'query-token-9c1e'] as $token) {
                $this->assertStringNotContainsString($token, $notifications, "notification carries {$token}");
                $this->assertStringNotContainsString($token, $audit, "audit carries {$token}");
            }
        });

        $exportId = $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202)->json('data.id');
        $export = $this->inTenant(fn () => TenantExport::query()->findOrFail($exportId));
        $this->assertSame(TenantExport::READY, $export->status, (string) $export->failure);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('media')->path($export->path)));
        $content = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content .= $zip->getFromIndex($i);
        }
        $this->assertStringContainsString('webhook_subscriptions', $content);
        foreach (['path-token-7f3a', 'query-token-9c1e', $created['secret']] as $token) {
            $this->assertStringNotContainsString($token, $content, "the export carries {$token}");
        }
    }

    // ---- Findings 5, 7, 8: indexes, the sweep, one delivery per event ----

    public function test_the_sweep_has_indexes_it_can_use_and_finds_each_kind_of_work(): void
    {
        $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'webhook_deliveries'"))->pluck('indexdef')->implode("\n");
        $this->assertStringContainsString('(replay_of)', $indexes);
        $this->assertStringContainsString('(created_at)', $indexes);
        $this->assertMatchesRegularExpression('/\\(updated_at\\) WHERE .*pending.*sending/', $indexes);
        $this->assertMatchesRegularExpression('/\\(next_attempt_at\\) WHERE .*retrying/', $indexes);
        $this->assertMatchesRegularExpression('/UNIQUE INDEX webhook_deliveries_once .*\\(subscription_id, event_id\\) WHERE \\(replay_of IS NULL\\)/', $indexes);
    }

    public function test_a_requeued_delivery_is_marked_and_queued_once(): void
    {
        $created = $this->subscribe();
        $id = $this->inTenant(function () use ($created) {
            $delivery = WebhookDelivery::create(['subscription_id' => $created['id'], 'event_id' => (string) Str::uuid7(), 'event_type' => 'thing.happened.v1', 'payload' => ['data' => []], 'status' => 'sending']);
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['updated_at' => now()->subHour()]);

            return $delivery->id;
        });
        Queue::fake();

        $this->artisan('webhooks:sweep')->assertSuccessful();
        Queue::assertPushed(SweepWebhooks::class, function ($job) {
            $this->inTenant(fn () => $job->handle());

            return true;
        });
        $row = $this->deliveries()->firstWhere('id', $id);
        $this->assertSame(WebhookDelivery::RETRYING, $row->status);
        $this->assertTrue($row->updated_at->greaterThan(now()->subMinute()), 'marked as queued again');
        Queue::assertPushed(SendWebhook::class, 1);

        // The same delivery is never queued twice while one waits (unique until it starts).
        SendWebhook::dispatch($this->owner->tenant_id, $id);
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public function test_a_fan_out_run_twice_writes_one_delivery_per_subscription(): void
    {
        Queue::fake();
        $this->subscribe();
        $eventId = (string) Str::uuid7();
        $captured = (new WebhookOccurrence(['thing_id' => 'x', 'company_id' => $this->acme->id], $this->acme->id))->toArray();

        $first = $this->inTenant(fn () => app(WebhookFanOut::class)->fanOut('thing.happened.v1', $eventId, now()->toIso8601ZuluString(), $captured));
        $second = $this->inTenant(fn () => app(WebhookFanOut::class)->fanOut('thing.happened.v1', $eventId, now()->toIso8601ZuluString(), $captured));

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertCount(1, $this->deliveries());
        Queue::assertPushed(SendWebhook::class, 1);
    }

    // ---- Finding 6: the cap's lock --------------------------------------

    public function test_the_cap_is_counted_under_a_per_tenant_lock(): void
    {
        $locked = false;
        DB::listen(function (QueryExecuted $query) use (&$locked) {
            $locked = $locked || str_contains($query->sql, 'pg_advisory_xact_lock');
        });

        $this->subscribe();

        $this->assertTrue($locked);
    }

    // ---- Findings 9 and 10: queue payloads and the event's own time ------

    public function test_a_sale_is_queued_as_ids_with_its_own_time(): void
    {
        Queue::fake();
        $this->subscribe(['events' => ['sale.completed.v1']]);

        $this->inTenant(fn () => app(WebhookDispatcher::class)->handle(new SaleCompleted($this->owner->tenant_id, 'sale-1', $this->acme->id, $this->locationA->id, [
            'id' => 'sale-1', 'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id,
            'receipt_number' => 'R-SECRETISH', 'sold_at' => '2026-10-15T07:30:00Z', 'totals' => ['total_minor' => '987654'],
        ])));

        Queue::assertPushed(FanOutWebhookEvent::class, function (FanOutWebhookEvent $job) {
            $queued = serialize($job);

            return $job->occurredAt === '2026-10-15T07:30:00Z'
                && $job->occurrence['data'] === ['sale_id' => 'sale-1']
                && ! str_contains($queued, 'R-SECRETISH') && ! str_contains($queued, '987654');
        });
    }

    public function test_principal_of_an_unknown_type_is_never_resolved_for_a_stored_subscription(): void
    {
        // Guard: a subscription row with a principal of a type nobody registered sends nothing.
        Http::fake();
        $this->inTenant(fn () => WebhookSubscription::create([
            'principal_type' => 'api_key', 'principal_id' => (string) Str::uuid7(), 'url' => 'https://hooks.example.com/k', 'url_display' => 'https://hooks.example.com/…/••••',
            'events' => ['thing.happened.v1'], 'secret' => WebhookSignature::newSecret(), 'created_by' => $this->owner->id,
        ]));

        $this->happen();

        $this->assertCount(0, $this->deliveries());
        Http::assertNothingSent();
    }
}
