<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\Sandbox\Sandbox;
use App\Core\Audit\AuditEntry;
use App\Core\Fiscal\FiscalDrivers;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// API-05 (ADR 020): a self-serve developer sandbox: sign-up with
// `sandbox: true`, every module on with sample data, never billed,
// metered or limited, fake payment and fiscal drivers forced, a reset
// endpoint, sk_test_ keys; isolated like any tenant.
class SandboxTest extends TestCase
{
    use BuildsApiKeys, RefreshTenantDatabase;

    /** @return array{tenant: string, token: string} */
    private function signUpSandbox(string $email = 'dev@example.com'): array
    {
        Notification::fake();
        $challenge = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Developer', 'email' => $email, 'password' => $this->password, 'country' => 'KE', 'locale' => 'en',
            'business_name' => 'Dev sandbox', 'sandbox' => true,
        ])->assertCreated()->json('challenge_id');

        $verified = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk();

        return ['tenant' => $verified->json('user.tenant_id'), 'token' => $verified->json('token')];
    }

    public function test_a_sandbox_sign_up_gets_every_module_sample_data_and_no_billing(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->signUpSandbox();

        $this->asTenant($tenant, function () {
            $this->assertTrue((bool) Tenant::query()->value('is_sandbox'));
            $this->assertTrue(app(Sandbox::class)->active());
            $optional = array_values(array_diff(app(ModuleRegistry::class)->registered(), [ModuleRegistry::CORE]));
            $this->assertEqualsCanonicalizing($optional, TenantModule::where('status', TenantModule::ACTIVE)->pluck('module')->all());
            $this->assertSame(OnboardingState::STAGE_DONE, OnboardingState::query()->value('stage'));
            $this->assertSame(12, Item::query()->whereNull('archived_at')->count());
            $this->assertSame(0, DB::table('subscriptions')->count());
        });

        // Never billed: choosing modules (a trial) and subscribing are refused.
        $this->putJson('/api/v1/onboarding/modules', ['modules' => ['pos'], 'cycle' => 'monthly'], $this->bearer($token))
            ->assertForbidden()->assertJsonPath('code', 'sandbox_not_billed');
        $this->postJson('/api/v1/subscription/subscribe', [], $this->bearer($token))->assertForbidden()->assertJsonPath('code', 'sandbox_not_billed');

        // Its keys are test keys.
        $admin = $this->asTenant($tenant, fn () => Role::where('template_key', 'admin')->value('id'));
        $secret = $this->postJson('/api/v1/api-keys', ['name' => 'Dev', 'role_id' => $admin, 'scope_type' => 'tenant', 'scopes' => ['core.item.read', 'pos.sale.read']], $this->bearer($token))
            ->assertCreated()->json('secret');
        $this->assertStringStartsWith('sk_test_', $secret);
        $this->getJson('/api/v1/items', $this->keyHeaders($secret))->assertOk()->assertJsonCount(12, 'data');
        // Module routes work in the sandbox (every module on) as far as the key's role reaches.
        $this->getJson('/api/v1/inventory/stock', $this->keyHeaders($secret))->assertForbidden()->assertJsonPath('scope', 'inventory.stock.read');
        $this->getJson('/api/v1/pos/sales', $this->keyHeaders($secret))->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_a_sandbox_is_never_metered_or_limited_and_uses_fake_drivers(): void
    {
        ['tenant' => $tenant] = $this->signUpSandbox();
        config(['payments.allow_fake' => false, 'fiscal.allow_fake' => false]);

        $this->asTenant($tenant, function () {
            $this->assertSame([], app(UsageLimits::class)->meter(CarbonImmutable::now()));
            DB::transaction(fn () => app(UsageLimits::class)->ensureCanAdd(Units::DEVICE));
            $this->assertSame(0, DB::table('usage_meters')->count());

            $mpesa = PaymentMethod::query()->where('provider', 'mpesa_ke')->firstOrFail();
            $this->assertSame('fake', app(PaymentProviderRegistry::class)->driverName($mpesa));
            $this->assertNotNull(app(PaymentProviderRegistry::class)->for($mpesa));
            $this->assertSame(['fake'], app(FiscalDrivers::class)->choices('KE'));
            $this->assertSame('fake', app(FiscalDrivers::class)->get('kra_etims_oscu')->name());
            $this->assertTrue(app(FiscalDrivers::class)->get('fake')->available());
        });

        // A live tenant keeps its configured drivers.
        $this->setUpOrganisation();
        $this->inTenant(function () {
            $this->assertFalse(app(Sandbox::class)->active());
            $this->assertNotContains('fake', app(FiscalDrivers::class)->choices('KE'));
        });
    }

    public function test_the_sandbox_flag_never_changes(): void
    {
        ['tenant' => $tenant] = $this->signUpSandbox();

        $this->expectException(QueryException::class);
        $this->asTenant($tenant, fn () => Tenant::query()->whereKey($tenant)->update(['is_sandbox' => false]));
    }

    public function test_reset_archives_the_data_and_loads_a_fresh_sample(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->signUpSandbox();
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Test customer', 'roles' => ['customer']], $this->bearer($token))->assertCreated();

        $archived = $this->postJson('/api/v1/sandbox/reset', [], $this->bearer($token))->assertOk()->json('data.archived');

        $this->assertSame(12, $archived['item']);
        $this->assertSame(4, $archived['party']);
        $this->asTenant($tenant, function () {
            $this->assertSame(12, Item::query()->whereNull('archived_at')->count());
            $this->assertSame(24, Item::query()->count(), 'archived, never deleted (TEN-06)');
            $this->assertSame(1, AuditEntry::where('action', 'core.sandbox.reset')->count());
        });
    }

    public function test_reset_is_refused_for_live_tenants_and_without_the_permission(): void
    {
        $this->setUpOrganisation();
        $this->postJson('/api/v1/sandbox/reset', [], $this->headersFor())->assertNotFound()->assertJsonPath('code', 'not_sandbox');

        ['token' => $token, 'tenant' => $tenant] = $this->signUpSandbox('dev2@example.com');
        $cashier = $this->asTenant($tenant, function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, Role::where('template_key', 'cashier')->firstOrFail(), Scope::tenant());

            return $user;
        });
        $this->postJson('/api/v1/sandbox/reset', [], $this->bearer($this->tokenFor($cashier)))->assertForbidden();
        $this->postJson('/api/v1/sandbox/reset', [], $this->bearer($token))->assertOk();
    }
}
