<?php

namespace Tests\Feature\Core\Onboarding;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Models\Subscription;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Onboarding\Checklist\Checklist;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Trial\TrialSelection;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Onboarding\Trial\TrialStatus;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\WorkflowDefinition;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ONB-01..ONB-07, AUTH-01, TEN-01, AUD-01: sign-up with the country and
 * language first, business type presets, the module selection and trial,
 * the setup wizard, sample data and the getting-started checklist.
 */
class OnboardingTest extends TestCase
{
    use BuildsRbac, CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** @return array{token: string, tenant: string, user: string} a verified owner of a new tenant */
    private function signUp(array $overrides = []): array
    {
        $challenge = $this->postJson('/api/v1/auth/sign-up', array_merge([
            'name' => 'Amani Kabila',
            'email' => 'amani-'.Str::lower(Str::random(6)).'@example.com',
            'password' => $this->password,
            'country' => 'CD',
            'locale' => 'fr',
            'business_name' => 'Pharmacie Amani',
        ], $overrides))->assertCreated()->json('challenge_id');

        $verified = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk();

        return ['token' => $verified->json('token'), 'tenant' => $verified->json('user.tenant_id'), 'user' => $verified->json('user.id')];
    }

    private function as(array $owner): array
    {
        return $this->bearer($owner['token']);
    }

    private function pickModules(array $owner, array $body = []): TestResponse
    {
        return $this->putJson('/api/v1/onboarding/modules', array_merge([
            'modules' => ['pos', 'inventory', 'loyalty'],
            'quantities' => ['outlet' => 1, 'device' => 2],
            'cycle' => 'monthly',
        ], $body), $this->as($owner));
    }

    public function test_sign_up_takes_country_and_language_first_and_creates_the_tenant_and_subdomain_within_a_minute(): void
    {
        $started = microtime(true);
        $challenge = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Amani Kabila', 'email' => 'amani@example.com', 'password' => $this->password,
            'country' => 'CD', 'locale' => 'fr', 'business_name' => 'Pharmacie Amani',
        ])->assertCreated()->json('challenge_id');
        $tenantId = VerificationChallenge::findOrFail($challenge)->tenant_id;

        // No subdomain before the contact is verified (no squatting).
        $this->asTenant($tenantId, fn () => $this->assertNull(Tenant::sole()->slug));
        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk();
        $elapsed = microtime(true) - $started;

        // ONB-01: the tenant and its subdomain exist in under a minute.
        $this->assertLessThan(60, $elapsed);

        $this->asTenant($tenantId, function () {
            $tenant = Tenant::sole();
            $this->assertSame(['fr', 'pharmacie-amani'], [$tenant->default_locale, $tenant->slug]);
            // The country set the currency and time zone; the language named the defaults.
            $company = Company::sole();
            $this->assertSame(['CD', 'USD', 'Africa/Kinshasa'], [$company->country, $company->base_currency, $company->timezone]);
            $this->assertSame(__('core.defaults.branch', [], 'fr'), $company->branches()->sole()->name);
            $this->assertSame(OnboardingState::STAGE_BUSINESS, OnboardingState::sole()->stage);
        });
    }

    public function test_the_subdomain_avoids_reserved_names_and_taken_slugs(): void
    {
        $first = $this->signUp(['business_name' => 'Duka Bora', 'country' => 'KE', 'locale' => 'en']);
        $second = $this->signUp(['business_name' => 'Duka Bora', 'country' => 'KE', 'locale' => 'en']);
        $reserved = $this->signUp(['business_name' => 'Admin', 'country' => 'KE', 'locale' => 'en']);

        $slug = fn (array $owner) => $this->asTenant($owner['tenant'], fn () => Tenant::sole()->slug);
        $this->assertSame('duka-bora', $slug($first));
        $this->assertMatchesRegularExpression('/^duka-bora-[a-z0-9]{4}$/', $slug($second));
        $this->assertMatchesRegularExpression('/^admin-[a-z0-9]{4}$/', $slug($reserved));
    }

    public function test_a_business_type_applies_its_pos_mode_role_templates_flows_and_suggestion(): void
    {
        $owner = $this->signUp();

        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'pharmacy', 'outlets' => 2], $this->as($owner) + ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'modules')
            ->assertJsonPath('data.business_type', 'pharmacy')
            ->assertJsonPath('data.pos_mode', 'pharmacy')
            ->assertJsonPath('data.applied.role_templates', ['admin', 'branch_manager', 'cashier', 'storekeeper', 'pharmacist', 'pharmacy_technician'])
            ->assertJsonPath('data.selection.modules', ['pos', 'inventory', 'pharmacy', 'loyalty'])
            ->assertJsonPath('data.selection.quantities.outlet', 2)
            ->assertJsonPath('data.selection.quantities.device', 4)
            ->assertJsonPath('data.business_types.5.name', 'Pharmacie ou agro-vétérinaire');

        // Wholesale adopts the credit limit flow (WF-02) as version 1.
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'wholesale'], $this->as($owner))
            ->assertOk()
            ->assertJsonPath('data.pos_mode', 'wholesale')
            ->assertJsonPath('data.applied.flows', ['core.credit_limit_change']);

        $this->asTenant($owner['tenant'], function () {
            $this->assertSame('wholesale', Tenant::sole()->settings['pos_mode']);
            $this->assertNotNull(WorkflowDefinition::where('document_type', 'core.credit_limit_change')->sole()->published()->first());
            $this->assertSame(2, AuditEntry::where('action', 'core.settings.update')->count());
            $this->assertGreaterThanOrEqual(1, AuditEntry::where('action', 'core.onboarding.update')->count());
        });

        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'casino'], $this->as($owner))
            ->assertUnprocessable()->assertJsonValidationErrors('business_type');
    }

    public function test_a_missing_role_template_is_seeded_when_the_business_type_needs_it(): void
    {
        $owner = $this->signUp();
        $this->asTenant($owner['tenant'], fn () => Role::where('template_key', 'storekeeper')->sole()->forceFill(['name' => 'Old storekeeper', 'template_key' => null, 'is_system' => false])->saveQuietly());

        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail'], $this->as($owner))->assertOk();

        $this->asTenant($owner['tenant'], fn () => $this->assertTrue(Role::where('template_key', 'storekeeper')->sole()->is_system));
    }

    public function test_choosing_modules_prices_the_selection_and_starts_the_trial_through_trial_starter(): void
    {
        $owner = $this->signUp();
        $spy = new class implements TrialStarter
        {
            /** @var list<TrialSelection> */
            public array $calls = [];

            public function start(TrialSelection $selection): TrialStatus
            {
                $this->calls[] = $selection;

                return TrialStatus::until(now()->addDays(14));
            }
        };
        $this->app->instance(TrialStarter::class, $spy);

        $this->pickModules($owner, ['cycle' => 'annual'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'setup')
            ->assertJsonPath('meta.quote.currency', 'USD')
            ->assertJsonPath('meta.quote.suggestion.bundle', 'starter');

        $this->assertCount(1, $spy->calls);
        $this->assertSame(['CD', ['pos', 'inventory', 'loyalty'], 'annual'], [$spy->calls[0]->country, $spy->calls[0]->modules, $spy->calls[0]->cycle]);
    }

    public function test_the_default_trial_activates_installed_modules_and_lasts_fourteen_days(): void
    {
        $owner = $this->signUp();
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'pharmacy'], $this->as($owner))->assertOk();

        $this->pickModules($owner)->assertOk()->assertJsonPath('data.trial.state', 'trial')->assertJsonPath('data.trial.days_left', 14);

        $this->asTenant($owner['tenant'], function () {
            // POS, Inventory and Loyalty are what was picked (the modules step sends them) and now active.
            $this->assertTrue(app(ModuleRegistry::class)->isActive('pos'));
            $this->assertSame(['inventory', 'loyalty', 'pos'], TenantModule::where('status', 'active')->orderBy('module')->pluck('module')->all());
            $state = OnboardingState::sole();
            $this->assertSame(['pos', 'inventory', 'loyalty'], $state->selection['modules']);
            $this->assertSame('pharmacy', $state->selection['business_type']);
            $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $state->trial_ends_at->timestamp, 5);
        });

        // Picking again keeps the end date and switches POS off when dropped.
        // ADR 016: the subscription holds the one trial end.
        $this->asTenant($owner['tenant'], fn () => Subscription::query()->sole()->forceFill(['trial_ends_at' => now()->addDays(11)->subMinute()])->saveQuietly());
        $this->pickModules($owner, ['modules' => ['accounting_essentials']])->assertOk()->assertJsonPath('data.trial.days_left', 11);
        $this->asTenant($owner['tenant'], fn () => $this->assertFalse(app(ModuleRegistry::class)->isActive('pos')));

        // Everyone sees the trial (the banner), whatever their role.
        $this->getJson('/api/v1/onboarding/trial', $this->as($owner))->assertOk()->assertJsonPath('data.state', 'trial')->assertJsonPath('data.days_left', 11);
    }

    public function test_a_selection_the_catalogue_refuses_is_422_and_starts_nothing(): void
    {
        $owner = $this->signUp();

        $this->pickModules($owner, ['modules' => ['brand_pack']])->assertUnprocessable()->assertJsonPath('code', 'addon_requires_bundle');
        $this->pickModules($owner, ['modules' => ['spaceships']])->assertUnprocessable()->assertJsonValidationErrors('modules.0');

        $this->asTenant($owner['tenant'], fn () => $this->assertNull(OnboardingState::sole()->trial_ends_at));
    }

    public function test_wizard_steps_can_be_skipped_marked_done_and_resumed(): void
    {
        $owner = $this->signUp();
        $this->pickModules($owner)->assertOk();
        $status = fn () => collect($this->getJson('/api/v1/onboarding', $this->as($owner))->assertOk()->json('data.steps'))->pluck('status', 'key')->all();

        $this->assertSame(array_fill_keys(['company', 'currencies', 'branches', 'taxes', 'payment_methods', 'items', 'users', 'devices'], 'todo'), $status());

        $this->postJson('/api/v1/onboarding/steps', ['step' => 'currencies', 'status' => 'skipped'], $this->as($owner))->assertOk();
        $this->postJson('/api/v1/onboarding/steps', ['step' => 'payment_methods', 'status' => 'done'], $this->as($owner))->assertOk();
        $this->assertSame(['skipped', 'done'], [$status()['currencies'], $status()['payment_methods']]);

        // Resumed later: back to "to do".
        $this->postJson('/api/v1/onboarding/steps', ['step' => 'currencies', 'status' => 'todo'], $this->as($owner))->assertOk();
        $this->assertSame('todo', $status()['currencies']);

        // Work done in the data counts: a tax ID finishes the company step.
        $company = $this->getJson('/api/v1/companies', $this->as($owner))->json('data.0.id');
        $this->patchJson("/api/v1/companies/{$company}", ['tax_id' => 'A1234567B'], $this->as($owner))->assertOk();
        $this->assertSame('done', $status()['company']);

        foreach (['currencies', 'branches', 'taxes', 'items', 'users', 'devices'] as $step) {
            $this->postJson('/api/v1/onboarding/steps', ['step' => $step, 'status' => 'skipped'], $this->as($owner))->assertOk();
        }
        $this->postJson('/api/v1/onboarding/steps', ['step' => 'company', 'status' => 'done'], $this->as($owner))
            ->assertOk()->assertJsonPath('data.stage', 'done');

        $this->postJson('/api/v1/onboarding/steps', ['step' => 'nap', 'status' => 'done'], $this->as($owner))->assertUnprocessable()->assertJsonValidationErrors('step');
    }

    public function test_sample_data_loads_as_one_batch_and_is_removed_in_one_click(): void
    {
        $owner = $this->signUp(['country' => 'KE', 'locale' => 'en', 'business_name' => 'Duka Bora']);

        $counts = $this->postJson('/api/v1/onboarding/sample-data', [], $this->as($owner))->assertCreated()->json('data.sample.counts');
        $this->assertEqualsCanonicalizing(['item_category' => 3, 'item' => 12, 'item_price' => 12, 'party' => 3, 'price_list' => 1], $counts);
        $this->postJson('/api/v1/onboarding/sample-data', [], $this->as($owner))->assertStatus(409)->assertJsonPath('code', 'sample_loaded');

        $this->asTenant($owner['tenant'], function () {
            $this->assertSame(12, Item::whereNull('archived_at')->count());
            $this->assertSame(['sample'], Party::firstOrFail()->tags);
            $this->assertSame('KES', ItemPrice::firstOrFail()->currency);
            $this->assertSame(12, AuditEntry::where('action', 'core.item.create')->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.sample_load')->count());
        });

        // Sample items are not "your first item".
        $this->assertFalse(collect($this->getJson('/api/v1/onboarding/checklist', $this->as($owner))->json('data.items'))->firstWhere('key', 'item')['done']);

        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $this->as($owner))->assertOk()->assertJsonPath('data.sample', null);

        $this->asTenant($owner['tenant'], function () {
            $this->assertSame(0, Item::whereNull('archived_at')->count());
            $this->assertSame(12, Item::whereNotNull('archived_at')->count(), 'archived, never deleted (TEN-06)');
            $this->assertSame(0, ItemCategory::whereNull('archived_at')->count());
            $this->assertSame(0, Party::whereNull('archived_at')->count());
            $this->assertSame(0, ItemPrice::whereNull('archived_at')->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.onboarding.sample_remove')->count());
        });

        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $this->as($owner))->assertNotFound()->assertJsonPath('code', 'sample_not_loaded');
        // Loadable again after removal.
        $this->postJson('/api/v1/onboarding/sample-data', [], $this->as($owner))->assertCreated();
    }

    public function test_sample_data_that_real_data_depends_on_is_not_removed(): void
    {
        $owner = $this->signUp(['country' => 'KE', 'locale' => 'en']);
        $this->postJson('/api/v1/onboarding/sample-data', [], $this->as($owner))->assertCreated();

        // A real item filed under a sample category.
        [$category, $uom] = $this->asTenant($owner['tenant'], fn () => [ItemCategory::firstOrFail()->id, Item::firstOrFail()->base_uom_id]);
        $this->postJson('/api/v1/items', ['code' => 'REAL-1', 'name' => 'Real item', 'type' => 'stock', 'base_uom_id' => $uom, 'category_id' => $category], $this->as($owner))->assertCreated();

        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $this->as($owner))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'sample_in_use')
            ->assertJsonPath('blocking.0.type', 'item_category');

        $this->asTenant($owner['tenant'], fn () => $this->assertSame(13, Item::whereNull('archived_at')->count(), 'nothing archived'));
        // The real item counts for the checklist.
        $this->assertTrue(collect($this->getJson('/api/v1/onboarding/checklist', $this->as($owner))->json('data.items'))->firstWhere('key', 'item')['done']);
    }

    public function test_the_checklist_follows_real_state_and_can_be_dismissed(): void
    {
        $owner = $this->signUp(['country' => 'KE', 'locale' => 'en']);
        $done = fn () => collect($this->getJson('/api/v1/onboarding/checklist', $this->as($owner))->assertOk()->json('data.items'))->pluck('done', 'key')->all();

        $this->assertSame(['company' => false, 'tax_id' => false, 'item' => false, 'till' => false], $done());

        $company = $this->getJson('/api/v1/companies', $this->as($owner))->json('data.0.id');
        $this->patchJson("/api/v1/companies/{$company}", ['tax_id' => 'P051234567X'], $this->as($owner))->assertOk();
        $this->asTenant($owner['tenant'], fn () => Device::forceCreate(['location_id' => Location::firstOrFail()->id, 'name' => 'Till 1', 'status' => Device::STATUS_ACTIVE]));

        $this->assertSame(['company' => true, 'tax_id' => true, 'item' => false, 'till' => true], $done());
        $this->getJson('/api/v1/onboarding/checklist', $this->as($owner))->assertJsonPath('data.visible', true)->assertJsonPath('data.done', 3);

        // POS adds the first sale, which ends the checklist (POS registers it; see PosOnboardingTest).
        $this->pickModules($owner)->assertOk();
        $this->assertArrayHasKey('first_sale', $done());

        $this->postJson('/api/v1/onboarding/checklist/dismiss', [], $this->as($owner))->assertOk()->assertJsonPath('data.visible', false)->assertJsonPath('data.dismissed', true);
    }

    public function test_an_item_marked_as_ending_hides_the_checklist_once_done(): void
    {
        $owner = $this->signUp(['country' => 'KE', 'locale' => 'en']);
        app(Checklist::class)->register('first_payroll', ModuleRegistry::CORE, fn () => true, 60, ends: true);

        $this->getJson('/api/v1/onboarding/checklist', $this->as($owner))->assertOk()->assertJsonPath('data.visible', false)->assertJsonPath('data.dismissed', false);
    }

    public function test_onboarding_needs_the_onboarding_permissions_but_everyone_sees_the_trial(): void
    {
        $owner = $this->signUp(['country' => 'KE', 'locale' => 'en']);
        $cashier = $this->asTenant($owner['tenant'], function () use ($owner) {
            $user = $this->colleague(User::findOrFail($owner['user']));
            $this->assign($user, Role::where('template_key', 'cashier')->sole(), Scope::tenant());

            return $user;
        });
        $headers = $this->bearer($this->tokenFor($cashier));

        $this->getJson('/api/v1/onboarding', $headers)->assertForbidden();
        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'retail'], $headers)->assertForbidden();
        $this->putJson('/api/v1/onboarding/modules', ['modules' => ['pos'], 'cycle' => 'monthly'], $headers)->assertForbidden();
        $this->postJson('/api/v1/onboarding/steps', ['step' => 'company', 'status' => 'done'], $headers)->assertForbidden();
        $this->postJson('/api/v1/onboarding/sample-data', [], $headers)->assertForbidden();
        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $headers)->assertForbidden();
        $this->getJson('/api/v1/onboarding/checklist', $headers)->assertForbidden();
        $this->postJson('/api/v1/onboarding/checklist/dismiss', [], $headers)->assertForbidden();
        $this->getJson('/api/v1/onboarding/trial', $headers)->assertOk()->assertJsonPath('data.state', 'none');
        $this->getJson('/api/v1/onboarding/trial')->assertUnauthorized();
    }

    public function test_another_tenants_onboarding_is_never_seen_or_changed(): void
    {
        $a = $this->signUp(['country' => 'KE', 'locale' => 'en', 'business_name' => 'Tenant A']);
        $b = $this->signUp(['country' => 'CD', 'locale' => 'fr', 'business_name' => 'Tenant B']);

        $this->putJson('/api/v1/onboarding/business', ['business_type' => 'bar'], $this->as($b))->assertOk();
        $this->postJson('/api/v1/onboarding/sample-data', [], $this->as($b))->assertCreated();
        $this->pickModules($b)->assertOk();

        $this->getJson('/api/v1/onboarding', $this->as($a))
            ->assertOk()
            ->assertJsonPath('data.business_type', null)
            ->assertJsonPath('data.country', 'KE')
            ->assertJsonPath('data.sample', null)
            ->assertJsonPath('data.trial.state', 'none');
        $this->postJson('/api/v1/onboarding/sample-data/remove', [], $this->as($a))->assertNotFound();

        $this->asTenant($a['tenant'], function () use ($b) {
            $this->assertSame(1, OnboardingState::count());
            $this->assertSame(0, OnboardingState::where('tenant_id', $b['tenant'])->count());
            $this->assertSame(0, Item::count());
        });
        $this->asTenant($b['tenant'], fn () => $this->assertSame(12, Item::whereNull('archived_at')->count()));
        app(TenantContext::class)->set(null);
    }
}
