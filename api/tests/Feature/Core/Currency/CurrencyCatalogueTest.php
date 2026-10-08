<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\Currencies;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\IcuCatalogue;
use App\Core\Currency\Models\Currency;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-01: the ISO 4217 catalogue, from ICU, with the project's CDF override.
class CurrencyCatalogueTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    public function test_the_catalogue_is_seeded_from_icu_with_cdf_at_zero_decimals(): void
    {
        $kes = Currency::findOrFail('KES');
        $this->assertSame(2, $kes->default_decimals);
        // Names come from ICU and vary by ICU version: present and translated.
        $this->assertNotSame('', trim($kes->name_en));
        $this->assertNotSame('', trim($kes->name_fr));
        $this->assertNotSame($kes->name_en, $kes->name_fr);
        $this->assertSame(404, $kes->numeric_code);
        $this->assertTrue($kes->active_in_iso);

        $cdf = Currency::findOrFail('CDF');
        $this->assertSame(0, $cdf->default_decimals, 'CDF is overridden to 0 decimals (CLAUDE.md)');
        $this->assertSame(976, $cdf->numeric_code);
        $this->assertNotSame('', trim($cdf->name_en));
        $this->assertNotSame($cdf->name_en, $cdf->name_fr);

        $this->assertSame(2, Currency::findOrFail('USD')->default_decimals);
        $this->assertSame(0, Currency::findOrFail('JPY')->default_decimals);
        $this->assertSame(3, Currency::findOrFail('BHD')->default_decimals);

        // Historic codes are kept, flagged as no longer in ISO use.
        $this->assertFalse(Currency::findOrFail('ZWD')->active_in_iso);
        $this->assertGreaterThan(140, Currency::where('active_in_iso', true)->count());
    }

    public function test_the_icu_reader_applies_the_overrides(): void
    {
        $rows = collect(IcuCatalogue::read())->keyBy('code');

        $this->assertSame(['CDF' => 0], IcuCatalogue::OVERRIDES);
        $this->assertSame(0, $rows['CDF']['default_decimals']);
        $this->assertSame(2, $rows['KES']['default_decimals']);
        $this->assertTrue($rows->every(fn (array $row) => preg_match('/^[A-Z]{3}$/', $row['code']) === 1));
    }

    public function test_currencies_sync_is_idempotent(): void
    {
        $before = DB::table('currencies')->count();

        $this->assertSame(0, Artisan::call('currencies:sync'));
        $this->assertSame(0, Artisan::call('currencies:sync'));

        $this->assertSame($before, DB::table('currencies')->count());
        $this->assertSame(0, Currency::findOrFail('CDF')->default_decimals);
    }

    public function test_the_runtime_role_may_only_read_the_catalogue(): void
    {
        $this->assertTrue((bool) DB::selectOne("select has_table_privilege(current_user, 'currencies', 'SELECT') as p")->p);

        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            $this->assertFalse(
                (bool) DB::selectOne("select has_table_privilege(current_user, 'currencies', '{$privilege}') as p")->p,
                "{$privilege} on currencies",
            );
        }

        try {
            DB::transaction(fn () => DB::update("update currencies set default_decimals = 5 where code = 'KES'"));
            $this->fail('The runtime role wrote to the currency catalogue');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    public function test_decimals_fall_back_from_the_catalogue_to_two(): void
    {
        $decimals = app(CurrencyDecimals::class);

        $this->assertSame(0, $decimals->for('CDF'));
        $this->assertSame(2, $decimals->for('KES'));
        $this->assertSame(3, $decimals->for('KWD'));
        $this->assertSame(2, $decimals->for('QQQ'));
    }

    public function test_the_catalogue_service_is_cached(): void
    {
        $currencies = app(Currencies::class);
        $this->assertSame(Currency::findOrFail('CDF')->name_en, $currencies->find('CDF')['name_en']);

        DB::enableQueryLog();
        $currencies->find('KES');
        app(Currencies::class)->all();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_get_currencies_lists_the_catalogue_in_the_request_language(): void
    {
        $this->setUpOrganisation();

        $response = $this->getJson('/api/v1/currencies', $this->headersFor())->assertOk();
        $cdf = collect($response->json('data'))->firstWhere('code', 'CDF');
        $row = Currency::findOrFail('CDF');
        $this->assertSame(['code' => 'CDF', 'numeric_code' => 976, 'name' => $row->name_en, 'default_decimals' => 0, 'active_in_iso' => true], $cdf);

        $fr = $this->getJson('/api/v1/currencies', $this->headersFor() + ['Accept-Language' => 'fr'])->assertOk();
        $this->assertSame($row->name_fr, collect($fr->json('data'))->firstWhere('code', 'CDF')['name']);
    }

    public function test_get_currencies_needs_the_view_permission_somewhere(): void
    {
        $this->setUpOrganisation();

        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/currencies', $this->headersFor($storekeeper))->assertForbidden();

        foreach (['cashier', 'waiter'] as $template) {
            $user = $this->userWith($template, Scope::location($this->locationA->id));
            $this->getJson('/api/v1/currencies', $this->headersFor($user))->assertOk();
        }

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/currencies', $this->headersFor($manager))->assertOk();
    }
}
