<?php

namespace Modules\POS\Tests;

use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Defaults\ModuleDefaults;
use App\Core\MasterData\Defaults\SalesDefaults;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Modules\POS\Models\Sale;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ONB-03, MD-03, CP-01, RBAC-08: switching POS on, by any route, gives each
// company a default price list, the country pack's tax categories and cash,
// idempotently and without touching what the tenant already has.
class ActivationDefaultsTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.seed_module_defaults' => true]);

        // A tenant that signed up without POS.
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->provisionFor($this->acme));
    }

    private function activate(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('pos'));
    }

    /** @return array{lists: int, categories: int, codes: int, cash: int} */
    private function counts(?Company $company = null): array
    {
        $company ??= $this->acme;

        return $this->inTenant(fn () => [
            'lists' => PriceList::where('company_id', $company->id)->count(),
            'categories' => TaxCategory::where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))->count(),
            'codes' => TaxCategoryCode::where('company_id', $company->id)->count(),
            'cash' => PaymentMethod::where('company_id', $company->id)->where('type', 'cash')->where('active', true)->count(),
        ]);
    }

    public function test_activating_pos_gives_a_price_list_tax_categories_and_cash_and_an_item_can_be_sold(): void
    {
        $this->assertSame(['lists' => 0, 'categories' => 0, 'codes' => 0, 'cash' => 0], $this->counts());

        $this->activate();

        $this->assertSame(['lists' => 1, 'categories' => 3, 'codes' => 3, 'cash' => 2], $this->counts());

        $this->inTenant(function () {
            $list = PriceList::where('company_id', $this->acme->id)->sole();
            $this->assertSame(['KES', true, true, 'Retail prices'], [$list->currency, $list->tax_inclusive, $list->is_default, $list->name]);

            $names = TaxCategory::orderBy('name')->pluck('name')->all();
            $this->assertSame(['Exempt', 'Standard-rated', 'Zero-rated'], $names);

            $standard = TaxCategory::where('name', 'Standard-rated')->sole();
            $code = TaxCode::findOrFail(TaxCategoryCode::where('tax_category_id', $standard->id)->sole()->tax_code_id);
            $this->assertSame('VAT_STD', $code->pack_code);
        });

        // Price an item through the API and sell it through the till API, at the pack's rate.
        [$this->till, $this->tillToken] = $this->pairedTill($this->locationA, 'Till 1');
        $item = $this->inTenant(function () {
            $each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $standard = TaxCategory::where('name', 'Standard-rated')->sole();

            return Item::create(['code' => 'TEA', 'name' => 'Tea', 'type' => 'stock', 'base_uom_id' => $each->id, 'tax_category_id' => $standard->id]);
        });
        $listId = $this->inTenant(fn () => PriceList::where('company_id', $this->acme->id)->sole()->id);
        $this->postJson("/api/v1/price-lists/{$listId}/prices", [
            'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'amount_minor' => '11600', 'currency' => 'KES', 'effective_from' => '2026-01-01',
        ], $this->headersFor())->assertCreated();

        $vat = $this->inTenant(fn () => TaxCode::where('company_id', $this->acme->id)->where('pack_code', 'VAT_STD')->sole());
        $cash = $this->inTenant(fn () => PaymentMethod::where('company_id', $this->acme->id)->where('type', 'cash')->where('currency', 'KES')->sole());
        $this->inTenant(fn () => $this->locationA->forceFill(['code' => 'L01'])->save());
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $line = [
            'id' => $this->id(), 'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'qty' => '1',
            'unit_price_minor' => '11600', 'list_price_minor' => '11600', 'price_list_id' => $listId, 'tax_inclusive' => true,
            'discount_minor' => '0', 'tax_code_id' => $vat->id, 'tax_rate' => '16.0000', 'tax_minor' => '1600', 'total_minor' => '11600', 'override' => null,
        ];
        $this->methods['cash_kes'] = $cash;
        $this->retail = $this->inTenant(fn () => PriceList::findOrFail($listId));
        $body = $this->saleBody($shift, 1, ['lines' => [$line], 'price_list_id' => $listId]);
        $body['payments'][0]['payment_method_id'] = $cash->id;

        $this->upload([$body])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->inTenant(fn () => $this->assertSame(1, Sale::count()));
    }

    public function test_activating_twice_changes_nothing(): void
    {
        $this->activate();
        $before = $this->counts();
        $this->activate();
        $this->assertSame($before, $this->counts());

    }

    public function test_existing_price_list_and_categories_are_left_alone(): void
    {
        $this->inTenant(function () {
            PriceList::create(['company_id' => $this->acme->id, 'name' => 'Mine', 'currency' => 'KES', 'tax_inclusive' => false, 'is_default' => true]);
            TaxCategory::create(['company_id' => $this->acme->id, 'name' => 'Groceries']);
        });

        $this->activate();

        $this->inTenant(function () {
            $list = PriceList::where('company_id', $this->acme->id)->sole();
            $this->assertSame(['Mine', false], [$list->name, $list->tax_inclusive]);
            $this->assertSame(['Groceries'], TaxCategory::pluck('name')->all());
            $this->assertSame(0, TaxCategoryCode::where('company_id', $this->acme->id)->count());
            // Cash is still given, as the till needs it.
            $this->assertSame(2, PaymentMethod::where('company_id', $this->acme->id)->where('type', 'cash')->where('active', true)->count());
        });
    }

    public function test_a_congo_company_gets_its_own_currency_and_categories_and_the_other_company_is_unaffected(): void
    {
        $congo = $this->inTenant(function () {
            $company = Company::create(['name' => 'Kinshasa SARL', 'legal_name' => 'Kinshasa SARL', 'country' => 'CD', 'base_currency' => 'CDF', 'fiscal_year_start_month' => 1, 'timezone' => 'Africa/Kinshasa']);
            app(TenantCurrencies::class)->provisionFor($company);

            return $company;
        });
        $other = $this->otherTenant();
        $this->inTenant(fn () => app(ModuleRegistry::class)->flush());

        $this->activate();

        $this->assertSame(['lists' => 1, 'categories' => 3, 'codes' => 3, 'cash' => 2], $this->counts());
        $this->inTenant(function () use ($congo) {
            $list = PriceList::where('company_id', $congo->id)->sole();
            $this->assertSame(['CDF', true], [$list->currency, $list->tax_inclusive]);
            $this->assertSame(3, TaxCategory::count());
            $this->assertSame(['VAT_EXEMPT', 'VAT_STD', 'VAT_ZERO'], TaxCode::whereIn('id', TaxCategoryCode::where('company_id', $congo->id)->pluck('tax_code_id'))->orderBy('pack_code')->pluck('pack_code')->all());
        });

        // Another tenant that never activated POS got nothing.
        $this->asTenant($other['user']->tenant_id, function () use ($other) {
            $this->assertSame(0, PriceList::where('company_id', $other['company']->id)->count());
            $this->assertSame(0, TaxCategory::count());
        });
    }

    public function test_other_modules_can_declare_their_own_defaults(): void
    {
        $defaults = app(ModuleDefaults::class);
        $this->assertSame([SalesDefaults::class], $defaults->for('pos'));
        $this->assertSame([], $defaults->for('hr'));
    }
}
