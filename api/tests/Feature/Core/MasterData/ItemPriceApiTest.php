<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\CurrencyUsage;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-03 follow-up, CUR-01, ADR 003: item prices per price list and unit, in
// minor units of the list's currency, effective-dated with quantity breaks;
// company scope (TEN-08), bulk all or nothing, archive (TEN-06), the
// resolver (unit fallback, rounding), permissions (RBAC-04), field rules
// (RBAC-05), audit (AUD-01) and history (MD-07).
class ItemPriceApiTest extends TestCase
{
    use BuildsOrganisation, ReadsListExports, RefreshTenantDatabase;

    private const TODAY = '2026-10-08';

    /** @var array<string, string> uom ids by code */
    private array $uoms;

    private string $kes;

    private string $soda;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 UTC is 13:00 in Nairobi: today is 2026-10-08 for the company.
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00:00', 'UTC'));
        $this->setUpOrganisation();
        $this->uoms = $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(TenantCurrencies::class)->activate('CDF');
            app(DefaultUoms::class)->seed();

            return Uom::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id])->all();
        });

        $this->kes = $this->postJson("/api/v1/companies/{$this->acme->id}/price-lists", ['name' => 'Retail', 'currency' => 'KES', 'is_default' => true], $this->headersFor())
            ->assertCreated()->json('data.id');
        $this->soda = $this->item('SODA', ['uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '12'], ['uom_id' => $this->uoms['PACK'], 'factor' => '2.5']]]);
    }

    private function item(string $code, array $extra = []): string
    {
        return $this->postJson('/api/v1/items', ['code' => $code, 'name' => "Item {$code}", 'type' => 'stock', 'base_uom_id' => $this->uoms['EA'], ...$extra], $this->headersFor())
            ->assertCreated()->json('data.id');
    }

    private function set(array $body, ?string $list = null, ?array $headers = null)
    {
        return $this->postJson('/api/v1/price-lists/'.($list ?? $this->kes).'/prices', [
            'item_id' => $this->soda, 'uom_id' => $this->uoms['EA'], 'currency' => 'KES', ...$body,
        ], $headers ?? $this->headersFor());
    }

    private function resolver(): PriceResolver
    {
        return app(PriceResolver::class);
    }

    public function test_a_price_is_set_listed_and_changed_in_place(): void
    {
        $id = $this->set(['amount_minor' => '12450'])->assertCreated()
            ->assertJsonPath('data.amount_minor', '12450')
            ->assertJsonPath('data.currency', 'KES')
            ->assertJsonPath('data.effective_from', self::TODAY)
            ->assertJsonPath('data.min_quantity', '1')
            ->assertJsonPath('data.item_code', 'SODA')
            ->assertJsonPath('data.uom_code', 'EA')
            ->json('data.id');

        // Same item, unit, day and break: the amount changes, no second row.
        $this->set(['amount_minor' => '13000', 'effective_from' => self::TODAY, 'min_quantity' => '1'])->assertOk()->assertJsonPath('data.id', $id);

        $this->getJson("/api/v1/price-lists/{$this->kes}/prices", $this->headersFor())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_minor', '13000')
            ->assertJsonPath('data.0.state', 'current')
            ->assertJsonPath('data.0.item_name', 'Item SODA')
            ->assertJsonPath('meta.today', self::TODAY)
            ->assertJsonPath('meta.currency', 'KES');

        $this->inTenant(function () use ($id) {
            $this->assertSame(1, ItemPrice::count());
            $update = AuditEntry::where('action', 'core.item_price.update')->sole();
            $this->assertSame($id, $update->auditable_id);
            $this->assertSame('12450', $update->before['amount_minor']);
            $this->assertSame('13000', $update->after['amount_minor']);
            // The whole price travels with each entry, so a history can say which one changed.
            $this->assertEquals(['price_list_id' => $this->kes, 'item_id' => $this->soda, 'uom_id' => $this->uoms['EA'], 'effective_from' => self::TODAY, 'min_quantity' => '1'], array_intersect_key($update->after, array_flip(['price_list_id', 'item_id', 'uom_id', 'effective_from', 'min_quantity'])));
            $this->assertSame(1, AuditEntry::where('action', 'core.item_price.create')->count());
        });
    }

    public function test_amounts_are_minor_units_in_the_lists_currency(): void
    {
        // ADR 003: a string of digits, never a float or a decimal.
        $this->set(['amount_minor' => 124.5])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->set(['amount_minor' => 12450])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->set(['amount_minor' => '124.50'])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->set(['amount_minor' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        // Larger than 2^53: exact.
        $this->set(['amount_minor' => '900719925474099312'])->assertCreated()->assertJsonPath('data.amount_minor', '900719925474099312');
        $this->set(['amount_minor' => '0', 'uom_id' => $this->uoms['BOX']])->assertCreated();

        // The list's currency only.
        $this->set(['amount_minor' => '100', 'currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->set(['amount_minor' => '100', 'currency' => 'kes'])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->inTenant(function () {
            // The database refuses another currency too (composite key to price_lists).
            try {
                ItemPrice::create(['price_list_id' => $this->kes, 'item_id' => $this->soda, 'uom_id' => $this->uoms['PACK'], 'amount_minor' => '1', 'currency' => 'USD', 'effective_from' => self::TODAY]);
                $this->fail('A price in another currency was stored.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key', $e->getMessage());
            }

            // CUR-01: KES decimals are now locked by stored prices.
            $this->assertTrue(app(CurrencyUsage::class)->isUsed('KES'));
        });

        // Its currency no longer changes.
        $this->patchJson("/api/v1/price-lists/{$this->kes}", ['currency' => 'USD'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->patchJson("/api/v1/price-lists/{$this->kes}", ['name' => 'Retail KES', 'currency' => 'KES'], $this->headersFor())->assertOk();
    }

    public function test_the_unit_is_the_items_and_the_item_the_lists_company_or_shared(): void
    {
        $this->set(['amount_minor' => '100', 'uom_id' => $this->uoms['KG']])->assertUnprocessable()->assertJsonValidationErrors('uom_id');
        $this->set(['amount_minor' => '1200', 'uom_id' => $this->uoms['BOX']])->assertCreated();

        [$beta, $betaItem, $acmeItem] = $this->inTenant(function () {
            $beta = $this->company('Beta');
            $make = fn (string $code, Company $company) => Item::create(['company_id' => $company->id, 'code' => $code, 'name' => $code, 'type' => 'stock', 'base_uom_id' => $this->uoms['EA']])->id;

            return [$beta, $make('BETA-1', $beta), $make('ACME-1', $this->acme)];
        });

        $this->set(['amount_minor' => '100', 'item_id' => $betaItem])->assertUnprocessable()->assertJsonValidationErrors('item_id');
        $this->set(['amount_minor' => '100', 'item_id' => $acmeItem])->assertCreated();

        // A list of Beta prices Beta's item, and the shared one.
        $usd = $this->postJson("/api/v1/companies/{$beta->id}/price-lists", ['name' => 'Beta USD', 'currency' => 'USD'], $this->headersFor())->assertCreated()->json('data.id');
        $this->set(['amount_minor' => '100', 'item_id' => $betaItem, 'currency' => 'USD'], $usd)->assertCreated();
        $this->set(['amount_minor' => '100', 'item_id' => $acmeItem, 'currency' => 'USD'], $usd)->assertUnprocessable()->assertJsonValidationErrors('item_id');
        $this->set(['amount_minor' => '100', 'currency' => 'USD'], $usd)->assertCreated();

        // An item that moves to another company drops out of the list (TEN-08).
        $this->inTenant(fn () => Item::findOrFail($acmeItem)->fill(['company_id' => $beta->id])->save());
        $this->assertSame(['SODA'], array_column($this->getJson("/api/v1/price-lists/{$this->kes}/prices", $this->headersFor())->json('data'), 'item_code'));

        // Archived items and lists take no price.
        $this->postJson("/api/v1/items/{$this->soda}/archive", [], $this->headersFor())->assertOk();
        $this->set(['amount_minor' => '100', 'uom_id' => $this->uoms['PACK']])->assertUnprocessable()->assertJsonValidationErrors('item_id');
        $this->postJson("/api/v1/price-lists/{$usd}/archive", [], $this->headersFor())->assertOk();
        $this->set(['amount_minor' => '100', 'item_id' => $betaItem, 'currency' => 'USD', 'effective_from' => '2026-12-01'], $usd)
            ->assertUnprocessable()->assertJsonPath('code', 'price_list_archived');
    }

    public function test_prices_are_effective_dated(): void
    {
        $this->set(['amount_minor' => '900', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->set(['amount_minor' => '1000'])->assertCreated();
        $this->set(['amount_minor' => '1200', 'effective_from' => '2026-11-01'])->assertCreated();
        $this->set(['amount_minor' => '1'])->assertOk();
        $this->set(['amount_minor' => '1000'])->assertOk();

        $states = fn (string $query) => collect($this->getJson("/api/v1/price-lists/{$this->kes}/prices?sort=effective_from{$query}", $this->headersFor())->assertOk()->json('data'))
            ->map(fn (array $row) => "{$row['effective_from']} {$row['amount_minor']} {$row['state']}")->all();
        $this->assertSame(['2026-01-01 900 replaced', '2026-10-08 1000 current', '2026-11-01 1200 scheduled'], $states(''));
        $this->assertSame(['2026-11-01 1200 scheduled'], $states('&state=scheduled'));
        $this->assertSame(['2026-10-08 1000 current'], $states('&state=current'));

        $this->inTenant(function () {
            $item = Item::findOrFail($this->soda);
            $list = PriceList::findOrFail($this->kes);
            $price = fn ($at) => $this->resolver()->priceFor($item, $this->uoms['EA'], $list, $at)?->money->minor();

            $this->assertSame('1000', $price(null));
            $this->assertSame('900', $price('2026-10-07'));
            $this->assertSame('1200', $price('2026-11-01'));
            $this->assertNull($price('2025-12-31'));
            // Dates are the company's: 21:30 UTC on 31 October is 1 November in Nairobi.
            $this->assertSame('1200', $price(CarbonImmutable::parse('2026-10-31 21:30:00', 'UTC')));
            $this->assertSame('1000', $price(CarbonImmutable::parse('2026-10-31 20:30:00', 'UTC')));
        });

        // The item's detail shows today's price and the scheduled one.
        $this->getJson("/api/v1/items/{$this->soda}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.prices.0.price_list_id', $this->kes)
            ->assertJsonPath('data.prices.0.can_edit', true)
            ->assertJsonPath('data.prices.0.prices.0.amount_minor', '1000')
            ->assertJsonCount(1, 'data.prices.0.prices')
            ->assertJsonPath('data.prices.0.scheduled.0.amount_minor', '1200');
    }

    public function test_the_resolver_falls_back_to_the_base_unit_and_rounds_to_the_currency(): void
    {
        $cdf = $this->postJson("/api/v1/companies/{$this->acme->id}/price-lists", ['name' => 'Francs', 'currency' => 'CDF', 'is_default' => true], $this->headersFor())
            ->assertCreated()->json('data.id');
        // CDF has no decimals: 1,001 francs a unit.
        $this->set(['amount_minor' => '1001', 'currency' => 'CDF'], $cdf)->assertCreated();
        $this->set(['amount_minor' => '900', 'currency' => 'CDF', 'min_quantity' => '10'], $cdf)->assertCreated();
        $this->set(['amount_minor' => '11000', 'currency' => 'CDF', 'uom_id' => $this->uoms['BOX']], $cdf)->assertCreated();

        $this->inTenant(function () use ($cdf) {
            $item = Item::findOrFail($this->soda);
            $list = PriceList::findOrFail($cdf);
            $resolve = fn (string $unit, string $quantity = '1') => $this->resolver()->priceFor($item, $this->uoms[$unit], $list, null, $quantity);

            $each = $resolve('EA');
            $this->assertSame(['1001', 'CDF', 'unit'], [$each->money->minor(), $each->money->currency(), $each->source]);
            // Quantity break: 10 or more units.
            $this->assertSame('900', $resolve('EA', '12')->money->minor());
            $this->assertSame('10', $resolve('EA', '12')->minQuantity);

            // An explicit box price wins over 12 × the unit price.
            $box = $resolve('BOX');
            $this->assertSame(['11000', 'unit', '1'], [$box->money->minor(), $box->source, $box->factor]);

            // No pack price: 2.5 units × 1,001 = 2,502.5, rounded half up once to the franc.
            $pack = $resolve('PACK');
            $this->assertSame(['2503', 'base', '2.5'], [$pack->money->minor(), $pack->source, $pack->factor]);
            // 4 packs are 10 base units: the 10-unit break applies (900 × 2.5).
            $this->assertSame('2250', $resolve('PACK', '4')->money->minor());

            // A unit the item doesn't have, or an item of another company: no price.
            $this->assertNull($resolve('KG'));
            $other = Item::create(['company_id' => $this->company('Beta')->id, 'code' => 'B1', 'name' => 'B1', 'type' => 'stock', 'base_uom_id' => $this->uoms['EA']]);
            $this->assertNull($this->resolver()->priceFor($other, $this->uoms['EA'], $list));

            // By company: its default list in the asked currency, else its base currency (KES, no price yet).
            $this->assertSame('1001', $this->resolver()->priceFor($item, $this->uoms['EA'], $this->acme, currency: 'CDF')->money->minor());
            $this->assertNull($this->resolver()->priceFor($item, $this->uoms['EA'], $this->acme));
            $this->assertNull($this->resolver()->priceFor($item, $this->uoms['EA'], $this->acme, currency: 'USD'));
        });

        // KES: two decimals; 2.5 × KES 10.01 = KES 25.025 rounds to KES 25.03.
        $this->set(['amount_minor' => '1001'])->assertCreated();
        $this->inTenant(function () {
            $resolved = $this->resolver()->priceFor(Item::findOrFail($this->soda), $this->uoms['PACK'], $this->acme);
            $this->assertSame(['2503', 'KES', $this->kes], [$resolved->money->minor(), $resolved->money->currency(), $resolved->priceListId]);
            $this->assertFalse($resolved->taxInclusive);
        });
    }

    public function test_bulk_is_all_or_nothing(): void
    {
        $row = fn (string $unit, string $amount, array $extra = []) => ['item_id' => $this->soda, 'uom_id' => $this->uoms[$unit], 'amount_minor' => $amount, 'currency' => 'KES', ...$extra];
        $url = "/api/v1/price-lists/{$this->kes}/prices/bulk";

        $this->postJson($url, ['prices' => [$row('EA', '100'), $row('BOX', '1100'), $row('KG', '5')]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('prices.2.uom_id');
        $this->postJson($url, ['prices' => [$row('EA', '100'), $row('EA', '120')]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('prices.1.item_id');
        $this->postJson($url, ['prices' => [$row('EA', '100'), $row('BOX', '1.5')]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('prices.1.amount_minor');
        $this->postJson($url, ['prices' => array_fill(0, 501, $row('EA', '100'))], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('prices');
        $this->inTenant(fn () => $this->assertSame(0, ItemPrice::count()));

        $this->set(['amount_minor' => '90'])->assertCreated();
        $this->postJson($url, ['prices' => [$row('EA', '100'), $row('BOX', '1100'), $row('EA', '95', ['min_quantity' => '6'])]], $this->headersFor())->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.uom_code', 'BOX')
            ->assertJsonPath('meta.created', 2)
            ->assertJsonPath('meta.updated', 1);

        $this->inTenant(fn () => $this->assertEqualsCanonicalizing(
            ['EA 1 100', 'EA 6 95', 'BOX 1 1100'],
            ItemPrice::with('uom')->get()->map(fn (ItemPrice $price) => strtoupper($price->uom->code)." {$price->minQuantity()} {$price->amount_minor}")->all(),
        ));
    }

    public function test_archive_and_restore(): void
    {
        $id = $this->set(['amount_minor' => '100'])->json('data.id');

        $this->postJson("/api/v1/item-prices/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', now()->toIso8601String());
        $this->getJson("/api/v1/price-lists/{$this->kes}/prices", $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/price-lists/{$this->kes}/prices?status=archived", $this->headersFor())->assertOk()->assertJsonCount(1, 'data');

        // A new price takes its place; the archived one can't come back over it.
        $this->set(['amount_minor' => '110'])->assertCreated();
        $this->postJson("/api/v1/item-prices/{$id}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'price_exists');

        $this->inTenant(function () use ($id) {
            $this->assertSame(1, AuditEntry::where('action', 'core.item_price.archive')->where('auditable_id', $id)->count());
            $this->assertSame(2, ItemPrice::count());
        });
    }

    public function test_permissions_by_company_scope(): void
    {
        $id = $this->set(['amount_minor' => '100'])->json('data.id');
        $list = "/api/v1/price-lists/{$this->kes}/prices";

        // Cashiers and branch managers read their company's prices; they don't change them.
        foreach (['cashier' => Scope::location($this->locationA->id), 'branch_manager' => Scope::branch($this->branchA->id)] as $template => $scope) {
            $headers = $this->headersFor($this->userWith($template, $scope));
            $this->getJson($list, $headers)->assertOk()->assertJsonCount(1, 'data');
            $this->set(['amount_minor' => '1'], headers: $headers)->assertForbidden();
            $this->postJson("/api/v1/price-lists/{$this->kes}/prices/bulk", ['prices' => []], $headers)->assertForbidden();
            $this->postJson("/api/v1/item-prices/{$id}/archive", [], $headers)->assertForbidden();
        }

        // Admins change them; a storekeeper doesn't see them.
        $admin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $this->set(['amount_minor' => '150'], headers: $admin)->assertOk();
        $storekeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->locationA->id)));
        $this->getJson($list, $storekeeper)->assertNotFound();
        $this->getJson("/api/v1/items/{$this->soda}", $storekeeper)->assertOk()->assertJsonMissingPath('data.prices');

        // A cashier sees the item's prices, without edit.
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationB->id)));
        $this->getJson("/api/v1/items/{$this->soda}", $cashier)->assertOk()
            ->assertJsonPath('data.prices.0.prices.0.amount_minor', '150')
            ->assertJsonPath('data.prices.0.can_edit', false);

        // Another tenant: not found.
        $other = $this->bearer($this->tokenFor($this->otherTenant()['user']));
        $this->getJson($list, $other)->assertNotFound();
        $this->postJson("/api/v1/item-prices/{$id}/archive", [], $other)->assertNotFound();
        $this->set(['amount_minor' => '1'], headers: $other)->assertNotFound();
    }

    public function test_field_rules_hide_and_freeze_prices(): void
    {
        $this->set(['amount_minor' => '100'])->assertCreated();

        [$hidden, $readonly] = $this->inTenant(function () {
            $make = function (string $mode) {
                $role = $this->role("Prices {$mode}", ['core.item.view', 'core.price.view', 'core.price.edit', 'core.price_list.view']);
                FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'prices', 'mode' => $mode]);
                $user = $this->colleague($this->owner);
                $this->assign($user, $role, Scope::tenant());

                return $this->headersFor($user);
            };

            return [$make('hidden'), $make('readonly')];
        });

        $this->getJson("/api/v1/price-lists/{$this->kes}/prices", $hidden)->assertOk()
            ->assertJsonPath('data.0.item_code', 'SODA')->assertJsonMissingPath('data.0.amount_minor');
        $this->getJson("/api/v1/price-lists/{$this->kes}/prices?sort=amount", $hidden)->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson("/api/v1/items/{$this->soda}", $hidden)->assertOk()->assertJsonMissingPath('data.prices');
        $this->set(['amount_minor' => '1'], headers: $hidden)->assertUnprocessable()->assertJsonPath('code', 'field_readonly');
        $this->getJson("/api/v1/history/item/{$this->soda}", $hidden)->assertOk()->assertJsonMissing(['action' => 'core.item_price.create']);
        $this->getJson("/api/v1/history/price_list/{$this->kes}", $hidden)->assertOk()->assertJsonMissing(['action' => 'core.item_price.create']);

        $this->getJson("/api/v1/price-lists/{$this->kes}/prices", $readonly)->assertOk()->assertJsonPath('data.0.amount_minor', '100');
        $this->getJson("/api/v1/items/{$this->soda}", $readonly)->assertOk()->assertJsonPath('data.prices.0.can_edit', false);
        $this->set(['amount_minor' => '1'], headers: $readonly)->assertUnprocessable()->assertJsonPath('code', 'field_readonly');
    }

    public function test_price_changes_show_in_the_item_and_price_list_history(): void
    {
        $this->set(['amount_minor' => '100'])->assertCreated();
        $this->set(['amount_minor' => '120'])->assertOk();

        foreach (["history/item/{$this->soda}", "history/price_list/{$this->kes}"] as $path) {
            $this->getJson("/api/v1/{$path}", $this->headersFor())->assertOk()
                ->assertJsonPath('data.0.action', 'core.item_price.update')
                ->assertJsonPath('data.0.before.amount_minor', '100')
                ->assertJsonPath('data.0.after.amount_minor', '120')
                ->assertJsonPath('data.0.after.uom_id', $this->uoms['EA'])
                ->assertJsonPath('data.1.action', 'core.item_price.create');
        }

        // A manager of another company's branch reads the shared item but not Acme's prices.
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $betaBranch = $this->inTenant(fn () => $this->branch($beta, 'BB'));
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($betaBranch->id)));
        $actions = array_column($this->getJson("/api/v1/history/item/{$this->soda}", $manager)->assertOk()->json('data'), 'action');
        $this->assertNotContains('core.item_price.update', $actions);
        $this->assertContains('core.item.create', $actions);
    }

    public function test_search_sort_and_export(): void
    {
        $water = $this->item('WATER', ['name' => 'Still water']);
        $this->set(['amount_minor' => '12450'])->assertCreated();
        $this->set(['amount_minor' => '5000', 'item_id' => $water])->assertCreated();
        $url = "/api/v1/price-lists/{$this->kes}/prices";

        $this->assertSame(['5000'], array_column($this->getJson("{$url}?search=still", $this->headersFor())->json('data'), 'amount_minor'));
        $this->assertSame(['5000', '12450'], array_column($this->getJson("{$url}?sort=amount", $this->headersFor())->json('data'), 'amount_minor'));
        $this->assertSame(['WATER', 'SODA'], array_column($this->getJson("{$url}?sort=-item_code", $this->headersFor())->json('data'), 'item_code'));

        $rows = $this->csvRows($this->get("{$url}?format=csv&columns[]=item_code&columns[]=amount&columns[]=effective_from", $this->headersFor())->assertOk());
        $this->assertSame([['Item code', 'Price', 'From'], ['SODA', 'KES 124.50', '8 Oct 2026'], ['WATER', 'KES 50.00', '8 Oct 2026']], $rows);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.item_price.export')->count()));
    }
}
