<?php

namespace Tests\Feature\Core\Onboarding;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\Support\SentInvitations;
use Tests\TestCase;

/**
 * ONB-08, AUD-01: platform staff create a tenant for a customer and hand
 * its ownership over by invitation; staff never keep tenant access.
 */
class AssistedOnboardingTest extends TestCase
{
    use CreatesIdentities, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
    }

    private function create(PlatformUser $staff, array $overrides = [])
    {
        return $this->postJson('/api/v1/platform/tenants', array_merge([
            'business_name' => 'Quincaillerie Lumière',
            'country' => 'CD',
            'locale' => 'fr',
            'business_type' => 'retail',
            'modules' => ['pos', 'inventory'],
            'quantities' => ['outlet' => 1, 'device' => 2],
            'cycle' => 'monthly',
            'contact_name' => 'Jeanne Mbuyi',
            'email' => 'jeanne@example.com',
        ], $overrides), $this->staffBearer($staff));
    }

    public function test_staff_create_a_tenant_and_the_customer_becomes_owner_while_staff_lose_access(): void
    {
        $staff = $this->staff(PlatformUser::ROLE_SUPPORT);
        $data = $this->create($staff)->assertCreated()->assertJsonPath('data.slug', 'quincaillerie-lumiere')->json('data');
        $tenantId = $data['tenant_id'];

        [$setup, $invitation] = $this->asTenant($tenantId, function () {
            $state = OnboardingState::sole();
            $this->assertSame(['retail', 'setup'], [$state->business_type, $state->stage]);
            $this->assertNotNull($state->trial_ends_at);
            $this->assertTrue(app(ModuleRegistry::class)->isActive('pos'));
            $this->assertSame('fr', Tenant::sole()->default_locale);

            $setup = User::where('is_platform_staff', true)->sole();
            $this->assertTrue(app(OwnerGuard::class)->isOwner($setup));
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.assisted_create')->count());

            return [$setup, Invitation::sole()];
        });
        $this->assertSame('jeanne@example.com', $invitation->email);

        // The setup account never signs in, whatever the password.
        $this->signIn($setup->email, 'anything-at-all-12')->assertUnprocessable()->assertJsonPath('code', 'invalid_credentials');

        $token = SentInvitations::lastToken();
        $ownerToken = $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'Jeanne Mbuyi', 'password' => 'amber-meadow-77'])
            ->assertCreated()->json('token');

        $this->asTenant($tenantId, function () use ($setup) {
            $jeanne = User::where('email', 'jeanne@example.com')->sole();
            $guard = app(OwnerGuard::class);
            $this->assertTrue($guard->isOwner($jeanne));
            // Staff access ends: the setup account holds nothing and is deactivated.
            $this->assertSame(0, RoleAssignment::where('user_id', $setup->id)->count());
            $this->assertSame(User::STATUS_DEACTIVATED, $setup->fresh()->status);
            $this->assertSame([$jeanne->id], $guard->ownerIds());

            $state = OnboardingState::sole();
            $this->assertNotNull($state->assisted['handed_over_at']);
            $this->assertSame($jeanne->id, $state->assisted['owner_id']);
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.handover')->count());
        });

        $this->getJson('/api/v1/onboarding', $this->bearer($ownerToken))->assertOk()->assertJsonPath('data.assisted.handed_over', true);

        $actions = PlatformDatabase::connection()->table('platform_audit_logs')->pluck('action')->all();
        $this->assertContains('platform.tenant.create', $actions);
        $this->assertContains('platform.tenant.handover', $actions);
        // A staff token reaches no tenant route.
        $this->getJson('/api/v1/onboarding', $this->staffBearer($staff))->assertUnauthorized();
    }

    public function test_check_then_create_holds_an_advisory_lock_on_the_lower_cased_contact(): void
    {
        // ONB-08: two staff creating for one contact at once make one tenant.
        $order = [];
        DB::listen(function ($query) use (&$order) {
            if (str_contains($query->sql, 'pg_advisory_xact_lock') && in_array('assisted:jeanne@example.com', $query->bindings, true)) {
                $order[] = 'lock';
            } elseif (str_contains($query->sql, 'app_assisted_tenant_for_contact')) {
                $order[] = 'check';
            }
        });

        $this->create($this->staff(PlatformUser::ROLE_SUPPORT), ['email' => 'Jeanne@Example.com'])->assertCreated();

        $this->assertSame(['lock', 'check'], array_slice($order, 0, 2));
    }

    public function test_only_owner_and_support_staff_may_create_tenants_and_tenant_tokens_are_refused(): void
    {
        $this->create($this->staff(PlatformUser::ROLE_BILLING))->assertForbidden();

        $tenantUser = $this->createUser();
        $this->postJson('/api/v1/platform/tenants', [], $this->bearer($this->tokenFor($tenantUser)))->assertUnauthorized();
        $this->postJson('/api/v1/platform/tenants', [])->assertUnauthorized();
    }

    public function test_the_contact_must_be_a_free_login_and_the_selection_sellable(): void
    {
        $staff = $this->staff();
        $taken = $this->createUser(['email' => 'taken@example.com']);

        $this->create($staff, ['email' => $taken->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->create($staff, ['modules' => ['brand_pack']])->assertUnprocessable()->assertJsonPath('code', 'addon_requires_bundle');
        $this->create($staff, ['business_type' => 'casino'])->assertUnprocessable()->assertJsonValidationErrors('business_type');
    }
}
