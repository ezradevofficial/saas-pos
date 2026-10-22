<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\Sandbox\SandboxClosingSoon;
use App\Core\Api\Sandbox\SandboxExpiry;
use App\Core\Api\Sandbox\SetUpSandboxJob;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\TemplateRenderer;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Marketplace\AppSecrets;
use App\Core\MasterData\Items\Item;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// API-05 review (ADR 020): sandboxes can't be farmed or abused: sign-up
// limits, hard caps, closing after inactivity, a stamp on every document,
// mail and SMS kept in an outbox, set-up only after verification.
class SandboxAbuseTest extends TestCase
{
    use BuildsApiKeys, BuildsMarketplace, RefreshTenantDatabase;

    private function signUp(string $email, bool $sandbox = true, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/sign-up', [
            'name' => 'Developer', 'email' => $email, 'password' => $this->password, 'country' => 'KE', 'locale' => 'en',
            'business_name' => 'Dev sandbox', 'sandbox' => $sandbox,
        ]);
    }

    /** @return array{tenant: string, token: string, user: string} */
    private function sandbox(?string $email = null): array
    {
        Notification::fake();
        $email ??= 'dev-'.Str::lower(Str::random(6)).'@example.com';
        $challenge = $this->signUp($email, true, '10.9.'.random_int(0, 250).'.'.random_int(1, 250))->assertCreated()->json('challenge_id');
        $verified = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk();

        return ['tenant' => $verified->json('user.tenant_id'), 'token' => $verified->json('token'), 'user' => $verified->json('user.id')];
    }

    public function test_sandbox_sign_ups_are_limited_per_ip_per_domain_and_overall(): void
    {
        Notification::fake();
        config(['api.sandbox.sign_ups.per_ip_per_hour' => 2, 'api.sandbox.sign_ups.per_domain_per_hour' => 3, 'api.sandbox.sign_ups.per_hour' => 4]);

        $this->signUp('a1@one.example', ip: '10.1.0.1')->assertCreated();
        $this->signUp('a2@two.example', ip: '10.1.0.1')->assertCreated();
        $this->signUp('a3@three.example', ip: '10.1.0.1')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        // A live sign-up is not a sandbox one.
        $this->signUp('live@three.example', false, '10.1.0.1')->assertCreated();

        $this->signUp('b1@two.example', ip: '10.1.0.2')->assertCreated();
        $this->signUp('b2@two.example', ip: '10.1.0.3')->assertCreated();
        $this->signUp('b3@two.example', ip: '10.1.0.4')->assertStatus(429);

        // The overall cap (4 so far: a1, a2, b1, b2).
        $this->signUp('c1@four.example', ip: '10.1.0.5')->assertStatus(429);
    }

    public function test_the_sandbox_is_set_up_by_a_queued_job_after_verification(): void
    {
        Notification::fake();
        Queue::fake([SetUpSandboxJob::class]);
        $challenge = $this->signUp('queued@example.com')->assertCreated()->json('challenge_id');
        Queue::assertNothingPushed();

        $tenantId = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk()->json('user.tenant_id');

        $this->asTenant($tenantId, function () {
            $this->assertSame(0, TenantModule::query()->count());
            $this->assertSame(0, Item::query()->count());
        });
        Queue::assertPushed(SetUpSandboxJob::class, fn (SetUpSandboxJob $job) => $job->tenantId === $tenantId);

        // The worker runs it in the sandbox's context (TenantAware).
        $job = Queue::pushed(SetUpSandboxJob::class)->first();
        $this->asTenant($job->tenantId, fn () => app()->call([$job, 'handle']));

        $this->asTenant($tenantId, function () {
            $this->assertGreaterThan(0, TenantModule::query()->where('status', TenantModule::ACTIVE)->count());
            $this->assertSame(12, Item::query()->count());
        });
    }

    public function test_hard_caps_refuse_more_users_devices_outlets_and_records(): void
    {
        config(['api.sandbox.caps.user' => 1, 'api.sandbox.caps.device' => 0, 'api.sandbox.caps.records' => 2]);
        ['tenant' => $tenant, 'token' => $token] = $this->sandbox();

        $this->asTenant($tenant, function () {
            try {
                DB::transaction(fn () => app(UsageLimits::class)->ensureCanAdd(Units::USER));
                $this->fail('a second user passed the sandbox cap');
            } catch (ApiException $e) {
                $this->assertSame('sandbox_limit_reached', $e->errorCode);
                $this->assertSame(403, $e->getStatusCode());
            }
        });

        $location = $this->asTenant($tenant, fn () => DB::table('locations')->value('id'));
        $this->postJson("/api/v1/locations/{$location}/devices", ['name' => 'Till'], $this->bearer($token))
            ->assertForbidden()->assertJsonPath('code', 'sandbox_limit_reached');

        $party = fn (string $name) => $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => $name, 'roles' => ['customer']], $this->bearer($token));
        $party('One')->assertCreated();
        $party('Two')->assertCreated();
        $party('Three')->assertForbidden()->assertJsonPath('code', 'sandbox_limit_reached');
    }

    public function test_api_requests_are_capped_per_day(): void
    {
        config(['api.sandbox.caps.api_requests_per_day' => 2]);
        ['tenant' => $tenant, 'token' => $token] = $this->sandbox();
        $admin = $this->asTenant($tenant, fn () => Role::where('template_key', 'admin')->value('id'));
        $secret = $this->postJson('/api/v1/api-keys', ['name' => 'Dev', 'role_id' => $admin, 'scope_type' => 'tenant', 'scopes' => ['core.item.read']], $this->bearer($token))
            ->assertCreated()->json('secret');

        $this->getJson('/api/v1/items', $this->keyHeaders($secret))->assertOk();
        $this->getJson('/api/v1/items', $this->keyHeaders($secret))->assertOk();
        $this->getJson('/api/v1/items', $this->keyHeaders($secret))->assertStatus(429)->assertJsonPath('code', 'sandbox_daily_limit');
    }

    public function test_an_inactive_sandbox_is_warned_then_closed(): void
    {
        ['tenant' => $tenant, 'token' => $token, 'user' => $owner] = $this->sandbox();
        $admin = $this->asTenant($tenant, fn () => Role::where('template_key', 'admin')->value('id'));
        $secret = $this->postJson('/api/v1/api-keys', ['name' => 'Dev', 'role_id' => $admin, 'scope_type' => 'tenant', 'scopes' => ['core.item.read']], $this->bearer($token))
            ->assertCreated()->json('secret');
        Notification::fake();

        $this->travel(22)->days();
        $this->artisan('sandbox:expire')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travel(2)->days();
        $this->artisan('sandbox:expire')->assertSuccessful();
        $user = $this->asTenant($tenant, fn () => User::findOrFail($owner));
        Notification::assertSentTo($user, SandboxClosingSoon::class);
        $this->artisan('sandbox:expire')->assertSuccessful();
        Notification::assertSentToTimes($user, SandboxClosingSoon::class, 1);
        $this->assertSame('active', $this->asTenant($tenant, fn () => Tenant::query()->value('status')));

        $this->travel(7)->days();
        $this->artisan('sandbox:expire')->assertSuccessful();
        $this->assertSame('closed', $this->asTenant($tenant, fn () => Tenant::query()->value('status')));
        $this->getJson('/api/v1/items', $this->keyHeaders($secret))->assertUnauthorized();
    }

    /**
     * A sandbox tenant idle for 40 days and its developer account, committed: an app is
     * listed outside the test's transaction (BuildsMarketplace), so its developer must be too.
     *
     * @return array{id: string, tenant_id: string}
     */
    private function committedSandboxDeveloper(): array
    {
        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $developer = ['id' => (string) Str::uuid7(), 'tenant_id' => (string) Str::uuid7()];
        $idle = now()->subDays(40);
        $owner->table('tenants')->insert(['id' => $developer['tenant_id'], 'name' => 'Idle sandbox', 'is_sandbox' => true, 'created_at' => $idle, 'updated_at' => $idle]);
        $owner->table('marketplace_developers')->insert([
            'id' => $developer['id'], 'developer_tenant_id' => $developer['tenant_id'], 'name' => 'Sandbox Labs', 'email' => 'dev@sandbox.example',
            'terms_accepted_at' => $idle, 'created_at' => $idle, 'updated_at' => $idle,
        ]);

        return $developer;
    }

    /** Remove what committedSandboxDeveloper() made; registered after the app's own clean-up, so it runs after it. */
    private function forgetSandboxDeveloper(array $developer): void
    {
        $this->beforeApplicationDestroyed(function () use ($developer) {
            $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
            $owner->table('marketplace_developers')->where('id', $developer['id'])->delete();
            $owner->table('tenants')->where('id', $developer['tenant_id'])->delete();
        });
    }

    /** Phase 8 review H2: closing an idle sandbox must not stop its listed apps for every customer. */
    public function test_a_sandbox_whose_developer_has_a_listed_app_is_never_closed_for_inactivity(): void
    {
        $developer = $this->committedSandboxDeveloper();
        $this->listedApp(developer: $developer);
        $this->forgetSandboxDeveloper($developer);

        $this->assertSame('active', $this->asTenant($developer['tenant_id'], fn () => app(SandboxExpiry::class)->check(CarbonImmutable::now())));
        $this->assertSame('active', $this->asTenant($developer['tenant_id'], fn () => Tenant::query()->value('status')));
        $this->assertTrue(app(AppSecrets::class)->developerActive($developer['id']));
    }

    /** Phase 8 review H2: only a live tenant's apps are listed; a sandbox closes when idle. */
    public function test_a_sandbox_developer_cannot_submit_an_app_for_listing(): void
    {
        ['token' => $token] = $this->sandbox();
        $this->putJson('/api/v1/developer', ['name' => 'Sandbox Labs', 'email' => 'dev@sandbox.example', 'accept_terms' => true], $this->bearer($token))->assertOk();
        $id = $this->postJson('/api/v1/developer/apps', [
            'slug' => 'sandbox-sync', 'name' => 'Sandbox sync', 'tagline' => 'Sync items', 'category' => 'productivity',
            'description' => 'Keeps your items and customers in step with the warehouse system, every hour.',
            'support_email' => 'help@sandbox.example', 'redirect_uris' => ['https://sync.sandbox.example/callback'],
            'scopes' => ['core.item.read'], 'pricing' => ['model' => 'free'],
        ], $this->bearer($token))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/developer/apps/{$id}/submit", [], $this->bearer($token))->assertForbidden()->assertJsonPath('code', 'developer_sandbox');
    }

    public function test_every_document_of_a_sandbox_is_stamped(): void
    {
        ['tenant' => $tenant] = $this->sandbox();

        $html = $this->asTenant($tenant, fn () => app(TemplateRenderer::class)->html('sales.quote', DefaultTemplates::for('sales.quote'), [], false));
        $this->assertStringContainsString('SANDBOX – not a fiscal receipt', $html);

        // The till's receipt templates carry the stamp as their first block.
        $this->setUpOrganisation();
        $live = $this->inTenant(fn () => app(TemplateRenderer::class)->html('sales.quote', DefaultTemplates::for('sales.quote'), [], false));
        $this->assertStringNotContainsString('SANDBOX', $live);
    }

    public function test_mail_and_sms_of_a_sandbox_go_to_its_outbox(): void
    {
        ['tenant' => $tenant, 'token' => $token, 'user' => $owner] = $this->sandbox();
        $transport = Mail::mailer()->getSymfonyTransport();
        $sentBefore = count($transport->messages());

        $this->asTenant($tenant, fn () => app(Notifier::class)->send(new NotificationEvent(NotificationsServiceProvider::TEST_EVENT, [$owner], [
            'sender_name' => 'Dev', 'message' => 'Hello outbox',
        ], '/notifications')));
        $this->asTenant($tenant, fn () => Mail::raw('Direct mail', fn ($m) => $m->to('someone@example.com')->subject('Direct')));

        $this->assertCount($sentBefore, $transport->messages(), 'nothing left the platform');
        $outbox = $this->getJson('/api/v1/sandbox/outbox', $this->bearer($token))->assertOk()->json('data');
        $this->assertContains('someone@example.com', array_column($outbox, 'recipient'));
        $this->assertTrue(collect($outbox)->contains(fn ($m) => $m['channel'] === 'email' && str_contains((string) $m['body'], 'Hello outbox')));

        // The in-app notification is still there.
        $this->assertSame(1, $this->asTenant($tenant, fn () => DB::table('notifications')->where('user_id', $owner)->count()));

        $this->setUpOrganisation();
        $this->getJson('/api/v1/sandbox/outbox', $this->headersFor())->assertNotFound();
    }
}
