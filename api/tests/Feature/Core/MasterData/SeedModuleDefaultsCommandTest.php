<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Identity\Models\VerificationChallenge;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ONB-03, MD-03, CP-01: `module-defaults:seed` gives a tenant that already
 * has POS on the price list, tax categories and cash it lacks, in its own
 * tenant, and a second run changes nothing. The command lists tenants as
 * the schema owner, which cannot see uncommitted rows, so this test commits
 * and the next test migrates afresh.
 */
class SeedModuleDefaultsCommandTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.seed_module_defaults' => true]);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function signUp(string $email, string $country): string
    {
        Notification::fake();
        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Owner', 'email' => $email, 'password' => $this->password, 'country' => $country, 'locale' => 'en', 'business_name' => 'Stores',
        ])->assertCreated()->json('challenge_id');

        return VerificationChallenge::findOrFail($challengeId)->tenant_id;
    }

    public function test_a_tenant_with_pos_but_no_defaults_is_backfilled_and_another_is_untouched(): void
    {
        $kenya = $this->signUp('ke@example.com', 'KE');
        $other = $this->signUp('other@example.com', 'KE');

        $this->asTenant($kenya, function () {
            app(ModuleRegistry::class)->activate('pos');
            // As on staging before this fix: POS on, nothing seeded.
            DB::table('tax_category_codes')->delete();
            DB::table('tax_categories')->delete();
            DB::table('price_lists')->delete();
            DB::table('payment_methods')->where('type', 'cash')->delete();
        });

        $this->assertSame(0, Artisan::call('module-defaults:seed'));
        $this->assertMatchesRegularExpression('/^\d+ records created in 2 tenants/', Artisan::output());

        $this->asTenant($kenya, function () {
            $this->assertSame(1, PriceList::query()->count());
            $this->assertSame(3, TaxCategory::query()->count());
            $this->assertSame(3, TaxCategoryCode::query()->count());
            $this->assertSame(2, PaymentMethod::query()->where('type', 'cash')->where('active', true)->count());
        });
        // POS was never switched on for the other tenant.
        $this->asTenant($other, function () {
            $this->assertSame(0, PriceList::query()->count());
            $this->assertSame(0, TaxCategory::query()->count());
        });

        Artisan::call('module-defaults:seed');
        $this->assertMatchesRegularExpression('/^0 records created/', Artisan::output());
    }
}
