<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * SUB-01, ONB-03: the seeded 8.4 catalogue, the public catalogue and
 * quote, and staff edits (role checks, versioned prices, coupons, audit).
 */
class CatalogueApiTest extends TestCase
{
    use CreatesPlatformStaff, RefreshTenantDatabase;

    private function quote(array $body, array $headers = [])
    {
        return $this->postJson('/api/v1/catalogue/quote', array_merge(['country' => 'KE', 'cycle' => 'monthly', 'modules' => []], $body), $headers);
    }

    public function test_concept_note_8_4_is_seeded_exactly(): void
    {
        $expected = [
            // key => [KES, USD, min KES, min USD] in minor units
            'pos' => [250000, 2000, null, null], 'pos_device' => [50000, 400, null, null],
            'inventory' => [150000, 1200, null, null], 'stores' => [100000, 800, null, null],
            'loyalty' => [100000, 800, null, null], 'ecommerce' => [250000, 2000, null, null],
            'accounting_essentials' => [350000, 2800, null, null], 'accounting_pro' => [700000, 5500, null, null],
            'procurement' => [300000, 2500, null, null], 'crm' => [200000, 1500, null, null],
            'fixed_assets' => [150000, 1200, null, null], 'manufacturing' => [400000, 3000, null, null],
            'hr' => [10000, 100, 150000, 1200], 'payroll' => [15000, 150, 200000, 1500],
            'brand_pack' => [150000, 1200, null, null],
        ];
        $ke = app(Catalogue::class)->snapshot('KE');
        $cd = app(Catalogue::class)->snapshot('CD');

        $this->assertSame(['KES', 'USD'], [$ke->currency, $cd->currency]);
        $this->assertSame(array_keys($expected), array_keys($ke->modules));
        foreach ($expected as $key => [$kes, $usd, $minKes, $minUsd]) {
            $this->assertSame([$kes, $usd, $minKes, $minUsd], [$ke->module($key)->amount, $cd->module($key)->amount, $ke->module($key)->minimum, $cd->module($key)->minimum], $key);
        }
        $this->assertSame(['pos_device' => 2], $ke->module('pos')->includes);
        $this->assertSame(['employee', 'employee', 'tenant', 'online_store'], [$ke->module('hr')->unit, $ke->module('payroll')->unit, $ke->module('brand_pack')->unit, $ke->module('ecommerce')->unit]);

        $this->assertSame([390000, null, ['pos', 'inventory', 'loyalty'], ['brand_pack']], [$ke->bundle('starter')->amount, $ke->bundle('starter')->extraOutletAmount, $ke->bundle('starter')->modules, $ke->bundle('starter')->addons]);
        $this->assertSame([990000, 400000, 7900, 3200], [$ke->bundle('business')->amount, $ke->bundle('business')->extraOutletAmount, $cd->bundle('business')->amount, $cd->bundle('business')->extraOutletAmount]);
        $this->assertSame([true, null], [$ke->bundle('enterprise')->quoted, $ke->bundle('enterprise')->amount]);
        $this->assertSame([10, 12], [$ke->annualMonthsCharged, $ke->monthsInCycle]);
    }

    public function test_the_public_catalogue_is_in_the_countrys_currency_and_the_callers_language(): void
    {
        $ke = $this->getJson('/api/v1/catalogue?country=KE')->assertOk();
        $ke->assertJsonPath('data.currency', 'KES')->assertJsonPath('data.annual.months_charged', 10);
        $pos = collect($ke->json('data.modules'))->firstWhere('key', 'pos');
        $this->assertSame(['key' => 'pos', 'kind' => 'module', 'unit' => 'outlet', 'name' => 'Point of sale', 'includes' => ['pos_device' => 2], 'requires' => null, 'amount' => 250000, 'minimum_amount' => null], $pos);
        $enterprise = collect($ke->json('data.bundles'))->firstWhere('key', 'enterprise');
        $this->assertSame([true, null], [$enterprise['quoted'], $enterprise['amount']]);

        $cd = $this->getJson('/api/v1/catalogue?country=CD', ['Accept-Language' => 'fr'])->assertOk();
        $cd->assertJsonPath('data.currency', 'USD');
        $this->assertSame('Point de vente', collect($cd->json('data.modules'))->firstWhere('key', 'pos')['name']);
        $this->assertSame('Démarrage', collect($cd->json('data.bundles'))->firstWhere('key', 'starter')['name']);

        $this->getJson('/api/v1/catalogue?country=UG')->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->getJson('/api/v1/catalogue')->assertUnprocessable();
    }

    public function test_the_public_quote_prices_the_selection_and_suggests_a_cheaper_bundle(): void
    {
        $this->quote(['modules' => ['pos', 'inventory', 'loyalty'], 'quantities' => ['outlet' => 1]])
            ->assertOk()
            ->assertJsonPath('data.total', 500000)
            ->assertJsonPath('data.currency', 'KES')
            ->assertJsonPath('data.suggestion', ['bundle' => 'starter', 'total' => 390000, 'saving' => 110000, 'currency' => 'KES']);

        $this->quote(['modules' => ['pos', 'inventory', 'loyalty'], 'bundle' => 'starter', 'cycle' => 'annual'])
            ->assertOk()->assertJsonPath('data.total', 3900000)->assertJsonPath('data.annual_discount', 780000);

        $this->quote(['modules' => ['pos'], 'bundle' => 'enterprise'])->assertOk()->assertJsonPath('data.quoted', true)->assertJsonPath('data.total', null);

        $this->quote(['country' => 'CD', 'modules' => ['hr'], 'quantities' => ['employee' => 5]])
            ->assertOk()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.total', 1200)->assertJsonPath('data.lines.0.minimum_applied', true);

        // Pricing errors are translated and name the field.
        $this->quote(['modules' => ['brand_pack']])->assertUnprocessable()
            ->assertJsonPath('code', 'addon_requires_bundle')
            ->assertJsonPath('errors.modules.0', 'Brand Pack is sold only with a bundle. Choose a bundle that offers it.');
        $this->quote(['modules' => ['brand_pack']], ['Accept-Language' => 'fr'])->assertUnprocessable()
            ->assertJsonPath('errors.modules.0', 'Pack marque n’est vendu qu’avec une offre groupée. Choisissez une offre qui le propose.');
        $this->quote(['modules' => ['pos', 'nope']])->assertUnprocessable()->assertJsonPath('code', 'unknown_module')->assertJsonValidationErrors('modules.1');
        $this->quote(['modules' => []])->assertUnprocessable()->assertJsonValidationErrors('modules');
        $this->quote(['modules' => ['pos'], 'quantities' => ['outlet' => 0]])->assertUnprocessable()->assertJsonValidationErrors('quantities.outlet');
        $this->quote(['modules' => ['pos'], 'quantities' => ['planet' => 2]])->assertUnprocessable()->assertJsonValidationErrors('quantities');
        $this->quote(['modules' => ['pos'], 'cycle' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors('cycle');
    }

    public function test_quotes_apply_only_usable_coupons(): void
    {
        DB::table('catalogue_coupons')->insert([
            ['id' => (string) str()->uuid7(), 'code' => 'LAUNCH10', 'kind' => 'percent', 'percent' => 10, 'amount' => null, 'currency' => null, 'expires_at' => null, 'max_redemptions' => null, 'redemptions' => 0, 'applies_to_modules' => '[]', 'applies_to_bundles' => '[]'],
            ['id' => (string) str()->uuid7(), 'code' => 'OLD', 'kind' => 'percent', 'percent' => 50, 'amount' => null, 'currency' => null, 'expires_at' => now()->subDay(), 'max_redemptions' => null, 'redemptions' => 0, 'applies_to_modules' => '[]', 'applies_to_bundles' => '[]'],
            ['id' => (string) str()->uuid7(), 'code' => 'GONE', 'kind' => 'fixed', 'percent' => null, 'amount' => 1000, 'currency' => 'KES', 'expires_at' => null, 'max_redemptions' => 3, 'redemptions' => 3, 'applies_to_modules' => '[]', 'applies_to_bundles' => '[]'],
        ]);

        $this->quote(['modules' => ['pos'], 'coupon' => 'launch10'])->assertOk()
            ->assertJsonPath('data.coupon_discount', 25000)->assertJsonPath('data.total', 225000)->assertJsonPath('data.coupon.code', 'LAUNCH10');

        foreach (['OLD', 'GONE', 'NOPE'] as $code) {
            $this->quote(['modules' => ['pos'], 'coupon' => $code])->assertUnprocessable()->assertJsonPath('code', 'coupon_invalid')->assertJsonValidationErrors('coupon');
        }
    }

    public function test_the_public_endpoints_are_rate_limited(): void
    {
        config(['platform.public_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/catalogue?country=KE')->assertOk();
        }
        $this->getJson('/api/v1/catalogue?country=KE')->assertTooManyRequests();
        $this->quote(['modules' => ['pos']])->assertTooManyRequests();
    }

    public function test_roles_decide_who_reads_and_who_changes_the_catalogue(): void
    {
        $support = $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT));
        $billing = $this->staffBearer($this->staff(PlatformUser::ROLE_BILLING));
        $coupon = ['code' => 'WELCOME', 'kind' => 'percent', 'percent' => 20];

        foreach (['modules', 'bundles', 'prices', 'coupons', 'annual-discounts'] as $list) {
            $this->getJson("/api/v1/platform/catalogue/{$list}", $support)->assertOk();
        }

        $this->postJson('/api/v1/platform/catalogue/coupons', $coupon, $support)->assertForbidden();
        $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'module', 'item_key' => 'pos', 'country' => 'KE', 'amount' => 1, 'effective_from' => now()->toDateString()], $support)->assertForbidden();
        $module = DB::table('catalogue_modules')->where('key', 'crm')->value('id');
        $this->postJson("/api/v1/platform/catalogue/modules/{$module}/archive", [], $support)->assertForbidden();
        $this->assertDatabaseMissing('catalogue_coupons', ['code' => 'WELCOME']);

        $this->postJson('/api/v1/platform/catalogue/coupons', $coupon, $billing)->assertCreated()->assertJsonPath('data.code', 'WELCOME')->assertJsonPath('data.status', 'active');

        // Unauthenticated callers get nothing.
        $this->getJson('/api/v1/platform/catalogue/modules')->assertUnauthorized();
    }

    public function test_staff_add_modules_and_bundles_with_both_names(): void
    {
        $owner = $this->staffBearer($this->staff());

        $this->postJson('/api/v1/platform/catalogue/modules', ['key' => 'sms_pack', 'kind' => 'addon', 'unit' => 'tenant', 'name' => ['en' => 'SMS pack']], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('name.fr');
        $this->postJson('/api/v1/platform/catalogue/modules', ['key' => 'pos', 'kind' => 'module', 'unit' => 'outlet', 'name' => ['en' => 'x', 'fr' => 'x']], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->postJson('/api/v1/platform/catalogue/modules', ['key' => 'sms_pack', 'kind' => 'addon', 'unit' => 'tenant', 'name' => ['en' => 'SMS pack', 'fr' => 'Pack SMS'], 'includes' => ['ghost' => 1]], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('includes.ghost');

        $module = $this->postJson('/api/v1/platform/catalogue/modules', ['key' => 'sms_pack', 'kind' => 'addon', 'unit' => 'tenant', 'name' => ['en' => 'SMS pack', 'fr' => 'Pack SMS'], 'sort' => 200], $owner)
            ->assertCreated()->assertJsonPath('data.key', 'sms_pack')->json('data');
        $this->patchJson("/api/v1/platform/catalogue/modules/{$module['id']}", ['unit' => 'outlet'], $owner)->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->patchJson("/api/v1/platform/catalogue/modules/{$module['id']}", ['name' => ['en' => 'SMS bundle', 'fr' => 'Forfait SMS']], $owner)->assertOk()->assertJsonPath('data.name.fr', 'Forfait SMS');

        // Add-ons per bundle: Business now offers the SMS pack too.
        $business = DB::table('catalogue_bundles')->where('key', 'business')->value('id');
        $this->patchJson("/api/v1/platform/catalogue/bundles/{$business}", ['addons' => ['pos']], $owner)->assertUnprocessable()->assertJsonValidationErrors('addons.0');
        $this->patchJson("/api/v1/platform/catalogue/bundles/{$business}", ['addons' => ['sms_pack']], $owner)->assertOk()->assertJsonPath('data.addons', ['sms_pack']);

        $this->postJson('/api/v1/platform/catalogue/bundles', ['key' => 'growth', 'name' => ['en' => 'Growth', 'fr' => 'Croissance'], 'modules' => ['pos', 'crm'], 'included_outlets' => 2], $owner)
            ->assertCreated()->assertJsonPath('data.modules', ['pos', 'crm'])->assertJsonPath('data.addons', []);

        // Archived modules leave the public catalogue; restored ones come back.
        $this->postJson("/api/v1/platform/catalogue/modules/{$module['id']}/archive", [], $owner)->assertOk();
        $crm = DB::table('catalogue_modules')->where('key', 'crm')->value('id');
        $this->postJson("/api/v1/platform/catalogue/modules/{$crm}/archive", [], $owner)->assertOk();
        $this->assertNotContains('crm', collect($this->getJson('/api/v1/catalogue?country=KE')->json('data.modules'))->pluck('key'));
        $this->postJson("/api/v1/platform/catalogue/modules/{$crm}/restore", [], $owner)->assertOk();
        $this->assertContains('crm', collect($this->getJson('/api/v1/catalogue?country=KE')->json('data.modules'))->pluck('key'));

        $actions = DB::table('platform_audit_logs')->orderBy('seq')->pluck('action')->all();
        $this->assertSame(['catalogue.module.created', 'catalogue.module.updated', 'catalogue.bundle.updated', 'catalogue.bundle.created', 'catalogue.module.archived', 'catalogue.module.archived', 'catalogue.module.restored'], $actions);
        $this->assertNotNull(DB::table('platform_audit_logs')->where('action', 'catalogue.module.updated')->value('before'));
        $this->assertNull(app(PlatformAuditor::class)->verify());
    }

    public function test_prices_are_versioned_by_date_and_never_edited(): void
    {
        $staff = $this->staff();
        $owner = $this->staffBearer($staff);
        $tomorrow = now()->addDay()->toDateString();

        $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'module', 'item_key' => 'pos', 'country' => 'KE', 'amount' => 270000, 'effective_from' => now()->subDay()->toDateString()], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'module', 'item_key' => 'pos', 'country' => 'KE', 'amount' => 270000, 'extra_outlet_amount' => 5, 'effective_from' => $tomorrow], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('extra_outlet_amount');
        $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'bundle', 'item_key' => 'pos', 'country' => 'KE', 'amount' => 1, 'effective_from' => $tomorrow], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('item_key');

        $price = $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'module', 'item_key' => 'pos', 'country' => 'KE', 'amount' => 270000, 'effective_from' => $tomorrow, 'currency' => 'USD'], $owner)
            ->assertCreated()->assertJsonPath('data.currency', 'KES')->assertJsonPath('data.status', 'scheduled')->json('data');
        $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'module', 'item_key' => 'pos', 'country' => 'KE', 'amount' => 280000, 'effective_from' => $tomorrow], $owner)
            ->assertConflict()->assertJsonPath('code', 'price_exists');

        // Today the old price applies; from tomorrow, the new one. The old row is untouched.
        $this->quote(['modules' => ['pos']])->assertJsonPath('data.total', 250000);
        $modules = $this->getJson('/api/v1/platform/catalogue/modules', $owner)->assertOk()->json('data');
        $pos = collect($modules)->firstWhere('key', 'pos');
        $this->assertSame([250000, 270000], [$pos['prices']['KE']['current']['amount'], $pos['prices']['KE']['next']['amount']]);

        $this->travel(1)->days();
        $this->quote(['modules' => ['pos']])->assertJsonPath('data.total', 270000);
        $this->assertSame(250000, (int) DB::table('catalogue_prices')->where(['item_key' => 'pos', 'country' => 'KE'])->orderBy('effective_from')->value('amount'));

        // Once in effect it cannot be archived; the history keeps both rows.
        $owner = $this->staffBearer($staff);
        $this->postJson("/api/v1/platform/catalogue/prices/{$price['id']}/archive", [], $owner)->assertConflict()->assertJsonPath('code', 'price_in_effect');
        $this->assertCount(2, $this->getJson('/api/v1/platform/catalogue/prices?item_type=module&item_key=pos&country=KE', $owner)->json('data'));
        $this->travelBack();
        $owner = $this->staffBearer($staff);

        // A scheduled price can be archived (a mistake caught before it applies).
        $next = $this->postJson('/api/v1/platform/catalogue/prices', ['item_type' => 'module', 'item_key' => 'crm', 'country' => 'CD', 'amount' => 1700, 'effective_from' => now()->addWeek()->toDateString()], $owner)->assertCreated()->json('data');
        $this->postJson("/api/v1/platform/catalogue/prices/{$next['id']}/archive", [], $owner)->assertOk()->assertJsonPath('data.status', 'archived');

        // The database refuses any edit of a price row, whoever asks.
        $seeded = CataloguePrice::where(['item_key' => 'pos', 'country' => 'KE'])->orderBy('effective_from')->first();
        foreach (['update catalogue_prices set amount = 1 where id = ?', 'update catalogue_prices set archived_at = now() where id = ?', 'delete from catalogue_prices where id = ?'] as $sql) {
            $refused = false;
            try {
                DB::transaction(fn () => DB::statement($sql, [$seeded->id]));
            } catch (QueryException) {
                $refused = true;
            }
            $this->assertTrue($refused, $sql);
        }

        $this->assertSame(['catalogue.price.created', 'catalogue.price.created', 'catalogue.price.archived'], DB::table('platform_audit_logs')->orderBy('seq')->pluck('action')->all());
    }

    public function test_the_annual_discount_is_data(): void
    {
        $owner = $this->staffBearer($this->staff());

        $this->postJson('/api/v1/platform/catalogue/annual-discounts', ['months_charged' => 13, 'effective_from' => now()->addDay()->toDateString()], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('months_charged');
        $this->postJson('/api/v1/platform/catalogue/annual-discounts', ['months_charged' => 11, 'effective_from' => now()->addDay()->toDateString()], $owner)
            ->assertCreated()->assertJsonPath('data.status', 'scheduled');

        $this->quote(['modules' => ['pos'], 'cycle' => 'annual'])->assertJsonPath('data.total', 2500000);
        $this->travel(1)->days();
        $this->quote(['modules' => ['pos'], 'cycle' => 'annual'])->assertJsonPath('data.total', 2750000)->assertJsonPath('data.months_charged', 11);
    }

    public function test_coupon_values_are_fixed_once_created(): void
    {
        $owner = $this->staffBearer($this->staff());

        $this->postJson('/api/v1/platform/catalogue/coupons', ['code' => 'FLAT', 'kind' => 'fixed', 'amount' => 50000], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/platform/catalogue/coupons', ['code' => 'MIX', 'kind' => 'percent', 'percent' => 10, 'amount' => 5], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/platform/catalogue/coupons', ['code' => 'BAD', 'kind' => 'fixed', 'amount' => 500, 'currency' => 'CDF'], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('currency');

        $coupon = $this->postJson('/api/v1/platform/catalogue/coupons', [
            'code' => 'flat500', 'kind' => 'fixed', 'amount' => 50000, 'currency' => 'KES',
            'applies_to_modules' => ['pos'], 'max_redemptions' => 100, 'expires_at' => now()->addMonth()->toIso8601String(),
        ], $owner)->assertCreated()->assertJsonPath('data.code', 'FLAT500')->json('data');
        $this->postJson('/api/v1/platform/catalogue/coupons', ['code' => 'Flat500', 'kind' => 'percent', 'percent' => 5], $owner)
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->patchJson("/api/v1/platform/catalogue/coupons/{$coupon['id']}", ['amount' => 900000], $owner)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->patchJson("/api/v1/platform/catalogue/coupons/{$coupon['id']}", ['max_redemptions' => 500, 'expires_at' => null], $owner)
            ->assertOk()->assertJsonPath('data.max_redemptions', 500)->assertJsonPath('data.expires_at', null);

        $this->quote(['modules' => ['pos', 'crm'], 'coupon' => 'FLAT500'])->assertOk()->assertJsonPath('data.coupon_discount', 50000);

        $this->postJson("/api/v1/platform/catalogue/coupons/{$coupon['id']}/archive", [], $owner)->assertOk()->assertJsonPath('data.status', 'archived');
        $this->quote(['modules' => ['pos'], 'coupon' => 'FLAT500'])->assertUnprocessable()->assertJsonPath('code', 'coupon_invalid');
    }
}
