<?php

namespace Tests\Feature\Core\Onboarding;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Authenticate;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Trial\TrialExpiry;
use App\Core\Onboarding\Trial\TrialSelection;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\Support\SentInvitations;
use Tests\TestCase;

/**
 * Review fixes for ONB-01, ONB-04, ONB-06, ONB-08 (AUTH-01, TEN-01,
 * AUD-01): the trial's end, the Owner-only module choice, stranded
 * assisted tenants, sign-up abuse, bundles, sample dependencies, the
 * setup account and the platform audit.
 */
class OnboardingReviewTest extends TestCase
{
    use BuildsRbac, CreatesIdentities, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
    }

    /** @return array{token: string, tenant: string, user: string} */
    private function signUp(array $overrides = []): array
    {
        $challenge = $this->postJson('/api/v1/auth/sign-up', array_merge([
            'name' => 'Amina Otieno',
            'email' => 'amina-'.Str::lower(Str::random(6)).'@example.com',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => 'Duka Bora',
        ], $overrides))->assertCreated()->json('challenge_id');
        $verified = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk();

        return ['token' => $verified->json('token'), 'tenant' => $verified->json('user.tenant_id'), 'user' => $verified->json('user.id')];
    }

    private function pick(array $owner, array $body = []): TestResponse
    {
        return $this->putJson('/api/v1/onboarding/modules', array_merge(['modules' => ['pos'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly'], $body), $this->bearer($owner['token']));
    }

    private function endTrial(array $owner): void
    {
        $this->asTenant($owner['tenant'], fn () => OnboardingState::sole()->forceFill(['trial_ends_at' => now()->subMinute()])->saveQuietly());
    }

    // ---- 1. The trial's end -------------------------------------------------

    public function test_modules_cannot_be_switched_on_after_the_trial_ends(): void
    {
        $owner = $this->signUp();
        $this->pick($owner)->assertOk();
        $this->endTrial($owner);

        $this->pick($owner, ['modules' => ['pos', 'inventory']])->assertStatus(409)->assertJsonPath('code', 'trial_ended');

        // The default starter refuses too, whoever calls it.
        $this->asTenant($owner['tenant'], function () {
            try {
                app(TrialStarter::class)->start(new TrialSelection('KE', ['pos'], ['outlet' => 1], 'monthly'));
                $this->fail('An ended trial must not start again.');
            } catch (ApiException $e) {
                $this->assertSame([409, 'trial_ended'], [$e->getStatusCode(), $e->errorCode]);
            }
        });
    }

    public function test_ended_trials_lose_their_modules_once_through_the_rebindable_expiry(): void
    {
        $owner = $this->signUp();
        $this->pick($owner)->assertOk();
        $this->asTenant($owner['tenant'], fn () => $this->assertTrue(app(ModuleRegistry::class)->isActive('pos')));

        // Not ended yet: nothing happens.
        $this->artisan('onboarding:expire-trials')->assertSuccessful();
        $this->asTenant($owner['tenant'], fn () => $this->assertTrue(app(ModuleRegistry::class)->isActive('pos')));

        $this->endTrial($owner);
        $this->artisan('onboarding:expire-trials')->assertSuccessful();
        $this->asTenant($owner['tenant'], function () {
            $this->assertFalse(app(ModuleRegistry::class)->isActive('pos'));
            $this->assertNotNull(OnboardingState::sole()->trial_expired_at);
            $this->assertSame(1, AuditEntry::where('action', 'core.module.update')->where('after->status', 'inactive')->count());
        });

        // Billing rebinds the expiry; a tenant is handled once.
        $calls = 0;
        $this->app->instance(TrialExpiry::class, new class($calls) implements TrialExpiry
        {
            public function __construct(private int &$calls) {}

            public function expire(): void
            {
                $this->calls++;
            }
        });
        $this->artisan('onboarding:expire-trials')->assertSuccessful();
        $this->assertSame(0, $calls);

        $other = $this->signUp();
        $this->pick($other)->assertOk();
        $this->endTrial($other);
        $this->artisan('onboarding:expire-trials')->assertSuccessful();
        $this->assertSame(1, $calls);
        $this->asTenant($other['tenant'], fn () => $this->assertTrue(app(ModuleRegistry::class)->isActive('pos'), 'billing decided to keep it'));
    }

    // ---- 2. The modules and business type are the Owner's ------------------

    public function test_an_admin_runs_the_wizard_but_cannot_change_modules_or_the_business_type(): void
    {
        $owner = $this->signUp();
        $admin = $this->asTenant($owner['tenant'], function () use ($owner) {
            $user = $this->colleague(User::findOrFail($owner['user']));
            $this->assign($user, Role::where('template_key', 'admin')->sole(), Scope::tenant());

            return $user;
        });
        $headers = $this->bearer($this->tokenFor($admin));

        $this->putJson('/api/v1/onboarding/modules', ['modules' => ['pos'], 'cycle' => 'monthly'], $headers)->assertForbidden();
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'bar'], $headers)->assertForbidden();
        $this->postJson('/api/v1/onboarding/steps', ['step' => 'taxes', 'status' => 'done'], $headers)->assertOk();
        $this->postJson('/api/v1/onboarding/sample-data', [], $headers)->assertCreated();
        $this->asTenant($owner['tenant'], fn () => $this->assertNotContains('core.onboarding.modules', Role::where('template_key', 'admin')->sole()->permissions()->pluck('name')->all()));
    }

    // ---- 3. Stranded assisted tenants ---------------------------------------

    private function assisted(PlatformUser $staff, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/platform/tenants', array_merge([
            'business_name' => 'Quincaillerie Lumière', 'country' => 'CD', 'locale' => 'fr', 'business_type' => 'retail',
            'modules' => ['pos'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly',
            'contact_name' => 'Jeanne Mbuyi', 'email' => 'jeanne@example.com',
        ], $overrides), $this->staffBearer($staff));
    }

    public function test_staff_resend_the_invitation_and_only_the_latest_one_hands_over(): void
    {
        $staff = $this->staff(PlatformUser::ROLE_SUPPORT);
        $tenantId = $this->assisted($staff)->assertCreated()->json('data.tenant_id');
        $first = SentInvitations::lastToken();

        $this->postJson("/api/v1/platform/tenants/{$tenantId}/reinvite", [], $this->staffBearer($staff))->assertOk();
        $second = SentInvitations::lastToken();
        $this->assertNotSame($first, $second);

        $this->postJson("/api/v1/auth/invitations/{$first}/accept", ['name' => 'Jeanne', 'password' => 'amber-meadow-77'])->assertStatus(410);
        $this->postJson("/api/v1/auth/invitations/{$second}/accept", ['name' => 'Jeanne', 'password' => 'amber-meadow-77'])->assertCreated();

        $this->asTenant($tenantId, function () {
            $this->assertNotNull(OnboardingState::sole()->assisted['handed_over_at']);
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.reinvite')->count());
            $this->assertSame(User::STATUS_DEACTIVATED, User::where('is_platform_staff', true)->sole()->status);
        });
        $this->assertSame(1, PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.tenant.reinvite')->count());

        $this->postJson("/api/v1/platform/tenants/{$tenantId}/reinvite", [], $this->staffBearer($staff))->assertStatus(409)->assertJsonPath('code', 'already_handed_over');
        $this->postJson("/api/v1/platform/tenants/{$tenantId}/reinvite", [], $this->staffBearer($this->staff(PlatformUser::ROLE_BILLING)))->assertForbidden();
        $this->postJson('/api/v1/platform/tenants/'.Str::uuid7().'/reinvite', [], $this->staffBearer($staff))->assertNotFound();
    }

    public function test_assisted_tenants_not_handed_over_in_30_days_are_listed_for_staff_never_deleted(): void
    {
        $staff = $this->staff(PlatformUser::ROLE_SUPPORT);
        $tenantId = $this->assisted($staff)->assertCreated()->json('data.tenant_id');

        $this->getJson('/api/v1/platform/tenants/awaiting-handover', $this->staffBearer($staff))->assertOk()->assertJsonCount(0, 'data');

        $this->travel(31)->days();
        $staff->refresh();
        $this->getJson('/api/v1/platform/tenants/awaiting-handover', $this->staffBearer($staff))
            ->assertOk()
            ->assertJsonPath('data.0.tenant_id', $tenantId)
            ->assertJsonPath('data.0.business_name', 'Quincaillerie Lumière')
            ->assertJsonPath('data.0.contact', fn (string $c) => $c !== 'jeanne@example.com');
        $this->artisan('onboarding:handover-report')->expectsOutputToContain('waiting for handover: 1')->assertSuccessful();
        $this->asTenant($tenantId, fn () => $this->assertSame('active', Tenant::sole()->status));
    }

    // ---- 4. Sign-up abuse -----------------------------------------------------

    public function test_sign_ups_are_limited_per_destination_and_globally(): void
    {
        $body = fn (array $extra = []) => array_merge(['name' => 'X', 'password' => $this->password, 'country' => 'KE', 'locale' => 'en', 'business_name' => 'Shop'], $extra);

        // The same address: three a hour (taken after the first, but every try counts).
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/sign-up', $body(['email' => 'same@example.com']))->assertStatus($i === 1 ? 201 : 422);
        }
        $this->postJson('/api/v1/auth/sign-up', $body(['email' => 'SAME@example.com']))->assertStatus(429);
    }

    public function test_sign_ups_are_limited_across_the_platform(): void
    {
        $body = fn (array $extra = []) => array_merge(['name' => 'X', 'password' => $this->password, 'country' => 'KE', 'locale' => 'en', 'business_name' => 'Shop'], $extra);
        config(['onboarding.sign_ups_per_hour' => 2]);
        $this->postJson('/api/v1/auth/sign-up', $body(['email' => 'one@example.com']))->assertCreated();
        $this->postJson('/api/v1/auth/sign-up', $body(['email' => 'two@example.com']))->assertCreated();
        $this->postJson('/api/v1/auth/sign-up', $body(['email' => 'three@example.com']))->assertStatus(429);
    }

    public function test_unverified_sign_ups_are_closed_after_a_day_and_the_address_freed(): void
    {
        $challenge = $this->postJson('/api/v1/auth/sign-up', ['name' => 'X', 'email' => 'late@example.com', 'password' => $this->password, 'country' => 'KE', 'locale' => 'en', 'business_name' => 'Late Shop'])
            ->assertCreated()->json('challenge_id');
        $tenantId = DB::table('verification_challenges')->where('id', $challenge)->value('tenant_id');
        $verified = $this->signUp();

        $this->artisan('onboarding:prune-pending')->assertSuccessful();
        $this->asTenant($tenantId, fn () => $this->assertSame('active', Tenant::sole()->status));

        $this->travel(25)->hours();
        $this->artisan('onboarding:prune-pending')->assertSuccessful();

        $this->asTenant($tenantId, function () {
            $this->assertSame(['closed', null], [Tenant::sole()->status, Tenant::sole()->slug]);
            $this->assertSame(User::STATUS_DEACTIVATED, User::sole()->status);
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.prune')->count());
        });
        $this->asTenant($verified['tenant'], fn () => $this->assertSame('active', Tenant::sole()->status));

        // The address can sign up again.
        $this->postJson('/api/v1/auth/sign-up', ['name' => 'X', 'email' => 'late@example.com', 'password' => $this->password, 'country' => 'KE', 'locale' => 'en', 'business_name' => 'Late Shop'])->assertCreated();
    }

    public function test_protected_brand_names_and_look_alikes_never_become_a_subdomain(): void
    {
        // Many sign-ups from one test client: the per-IP limit is tested in LoginSecurityTest.
        RateLimiter::for('auth-ip', fn () => Limit::none());
        $slug = fn (array $owner) => $this->asTenant($owner['tenant'], fn () => Tenant::sole()->slug);

        foreach (['Safaricom', 'Safaricom Agent Westlands', '5afaric0m', 'M-Pesa Point', 'Best Mpesa Shop'] as $name) {
            $this->assertMatchesRegularExpression('/^shop-[a-z0-9]{4}$/', $slug($this->signUp(['business_name' => $name])), $name);
        }
        $this->assertSame('duka-bora', $slug($this->signUp(['business_name' => 'Duka Bora'])));

        $owner = $this->signUp(['business_name' => 'Duka Bora']);
        $this->putJson('/api/v1/branding/settings', ['slug' => 'equ1ty'], $this->bearer($owner['token']))->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    // ---- 5, 6. Bundles ---------------------------------------------------------

    public function test_a_bundle_switches_on_its_modules_and_a_quoted_bundle_is_refused(): void
    {
        $owner = $this->signUp();

        $this->pick($owner, ['modules' => [], 'bundle' => 'starter'])->assertOk();
        $this->asTenant($owner['tenant'], fn () => $this->assertTrue(app(ModuleRegistry::class)->isActive('pos')));

        $other = $this->signUp();
        $this->pick($other, ['modules' => [], 'bundle' => 'enterprise'])->assertUnprocessable()->assertJsonPath('code', 'bundle_quoted');
        $this->asTenant($other['tenant'], fn () => $this->assertNull(OnboardingState::sole()->trial_ends_at));
    }

    // ---- 7. Sample dependencies ----------------------------------------------

    public function test_sample_data_named_by_a_custom_field_or_a_pos_layout_is_not_removed(): void
    {
        $owner = $this->signUp();
        $this->postJson('/api/v1/onboarding/sample-data', [], $this->bearer($owner['token']))->assertCreated();

        // A real item whose lookup field names a sample customer.
        [$real, $party] = $this->asTenant($owner['tenant'], function () {
            $sample = Item::firstOrFail();
            $real = Item::create(['code' => 'REAL-1', 'name' => 'Real', 'type' => 'stock', 'base_uom_id' => $sample->base_uom_id]);

            return [$real, Party::firstOrFail()];
        });
        $this->asTenant($owner['tenant'], fn () => DB::table('items')->where('id', $real->id)->update(['custom' => json_encode(['supplier' => $party->id])]));

        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $this->bearer($owner['token']))
            ->assertUnprocessable()->assertJsonPath('code', 'sample_in_use')->assertJsonPath('blocking.0.used_by', 'items');

        // Cleared: now a draft POS layout pins a sample item.
        $this->asTenant($owner['tenant'], function () use ($real) {
            DB::table('items')->where('id', $real->id)->update(['custom' => '{}']);
            $doc = (string) Str::uuid7();
            DB::table('config_documents')->insert(['id' => $doc, 'tenant_id' => app(TenantContext::class)->id(), 'kind' => 'pos_layout', 'key' => 'default', 'scope_type' => 'tenant', 'scope_id' => null, 'name' => 'Till', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('config_versions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => app(TenantContext::class)->id(), 'document_id' => $doc, 'version' => 1, 'status' => 'draft',
                'payload' => json_encode(['products' => ['pinned' => [Item::where('code', 'like', 'SAMPLE-%')->firstOrFail()->id]]]), 'source' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $this->bearer($owner['token']))
            ->assertUnprocessable()->assertJsonPath('blocking.0.used_by', 'pos_layout');
    }

    // ---- 8. The setup account -------------------------------------------------

    public function test_the_setup_account_never_gets_a_token_and_a_token_it_holds_is_refused(): void
    {
        $owner = $this->signUp();
        $setup = $this->asTenant($owner['tenant'], fn () => tap($this->colleague(User::findOrFail($owner['user'])))->forceFill(['is_platform_staff' => true])->save() ? User::where('is_platform_staff', true)->sole() : null);

        $this->asTenant($owner['tenant'], function () use ($setup) {
            try {
                app(Authenticate::class)->issueToken($setup, '127.0.0.1', 'tests');
                $this->fail('A setup account must never get a token.');
            } catch (ApiException $e) {
                $this->assertSame([403, 'setup_account'], [$e->getStatusCode(), $e->errorCode]);
            }
        });

        $token = $this->asTenant($owner['tenant'], fn () => $setup->createDeviceToken('tests', '127.0.0.1', 'tests')->plainTextToken);
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    // ---- 9. Platform audit and retries ---------------------------------------

    public function test_a_retried_create_returns_the_same_tenant_and_a_failed_one_writes_no_platform_audit(): void
    {
        $staff = $this->staff(PlatformUser::ROLE_SUPPORT);
        $logs = fn (string $action) => PlatformDatabase::connection()->table('platform_audit_logs')->where('action', $action)->count();

        $this->assisted($staff, ['email' => 'nope@example.com', 'modules' => ['brand_pack']])->assertUnprocessable();
        $this->assertSame(0, $logs('platform.tenant.create'));

        $first = $this->assisted($staff)->assertCreated()->json('data');
        $again = $this->assisted($staff)->assertOk()->assertJsonPath('data.existing', true)->json('data');
        $this->assertSame([$first['tenant_id'], $first['invitation_id']], [$again['tenant_id'], $again['invitation_id']]);
        $this->assertSame(1, $logs('platform.tenant.create'));
    }

    // ---- 10. The first read ---------------------------------------------------

    public function test_the_first_read_creates_one_record_and_audits_it_once(): void
    {
        $user = $this->createUser();

        $this->asTenant($user->tenant_id, function () {
            $a = OnboardingState::current();
            $b = OnboardingState::current();
            $this->assertSame($a->id, $b->id);
            $this->assertSame(1, OnboardingState::count());
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.create')->count());
            // A row someone else inserted first is kept, not duplicated.
            $this->assertSame(0, OnboardingState::query()->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => app(TenantContext::class)->id(), 'stage' => 'business', 'steps' => '{}']));
        });
    }

    public function test_tenant_modules_follow_the_selection_only_during_the_trial(): void
    {
        $owner = $this->signUp();
        $this->pick($owner)->assertOk();
        $this->endTrial($owner);
        $this->pick($owner, ['modules' => []])->assertStatus(409);
        $this->asTenant($owner['tenant'], fn () => $this->assertSame(['pos'], TenantModule::where('status', 'active')->pluck('module')->all()));
    }
}
