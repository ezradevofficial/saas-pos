<?php

namespace Tests\Feature\Core\Lists;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Lists and pickers plan, task 3: search, sort and export (EXP-01) on tax
// codes, tax categories and price lists (MD-03), audited (AUD-01), never
// another tenant's rows (TEN-01). Percentages are test inputs, not
// real-world rates.
class TaxListsTest extends TestCase
{
    use BuildsOrganisation, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(TenantCurrencies::class)->activate('KES');
            app(TenantCurrencies::class)->activate('USD');
        });
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes/apply-pack", [], $this->headersFor())->assertOk();
    }

    /** @return array<string, string> tax code ids by code */
    private function codeIds(): array
    {
        return collect($this->getJson("/api/v1/companies/{$this->acme->id}/tax-codes", $this->headersFor())->assertOk()->json('data'))->pluck('id', 'code')->all();
    }

    private function priceList(string $name, string $currency, bool $inclusive = true, bool $default = false): string
    {
        return $this->postJson("/api/v1/companies/{$this->acme->id}/price-lists", ['name' => $name, 'currency' => $currency, 'tax_inclusive' => $inclusive, 'is_default' => $default], $this->headersFor())
            ->assertCreated()->json('data.id');
    }

    public function test_tax_codes_search_sort_and_export(): void
    {
        $codes = $this->codeIds();
        $this->postJson("/api/v1/tax-codes/{$codes['VAT_STD']}/rates", ['rate' => '12.5', 'effective_from' => '2026-01-01'], $this->headersFor())->assertCreated();
        $url = "/api/v1/companies/{$this->acme->id}/tax-codes";

        $this->assertSame([$codes['VAT_EXEMPT'], $codes['VAT_STD'], $codes['VAT_WHT'], $codes['VAT_ZERO']], $this->listIds($url, $this->headersFor()));
        $this->assertSame([$codes['VAT_ZERO'], $codes['VAT_WHT'], $codes['VAT_STD'], $codes['VAT_EXEMPT']], $this->listIds("{$url}?sort=-code", $this->headersFor()));
        $this->assertSame([$codes['VAT_ZERO']], $this->listIds("{$url}?search=zero", $this->headersFor()));
        $this->getJson("{$url}?sort=rate", $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get("{$url}?format=csv", $this->headersFor())->assertOk());
        $this->assertSame(['Code', 'Name', 'Kind', 'Rate', 'Since', 'Fiscal code', 'Status', 'Updated'], $rows[0]);
        $byCode = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame(['VAT_EXEMPT', 'Exempt', 'Exempt', ''], [$byCode['VAT_EXEMPT'][0], $byCode['VAT_EXEMPT'][2], $byCode['VAT_EXEMPT'][3], $byCode['VAT_EXEMPT'][4]]);
        $this->assertSame(['VAT_STD', 'VAT', '12.5%', '1 Jan 2026', 'Active'], [$byCode['VAT_STD'][0], $byCode['VAT_STD'][2], $byCode['VAT_STD'][3], $byCode['VAT_STD'][4], $byCode['VAT_STD'][6]]);
        $this->assertSame('0%', $byCode['VAT_ZERO'][3]);

        $fr = $this->csvRows($this->get("{$url}?format=csv&search=VAT_STD&columns[]=kind&columns[]=rate&columns[]=since", [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Type', 'Taux', 'Depuis'], ['TVA', '12,5%', '1 janv. 2026']], $fr);

        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'core.tax_code.export')->count()));
    }

    public function test_a_tax_code_without_a_confirmed_rate_exports_as_rate_needed(): void
    {
        $rows = $this->csvRows($this->get("/api/v1/companies/{$this->acme->id}/tax-codes?format=csv&search=VAT_STD&columns[]=code&columns[]=rate", $this->headersFor())->assertOk());

        $this->assertSame([['Code', 'Rate'], ['VAT_STD', 'Rate needed']], $rows);
    }

    public function test_tax_categories_search_sort_and_export(): void
    {
        $codes = $this->codeIds();
        $goods = $this->postJson('/api/v1/tax-categories', ['name' => 'Goods', 'codes' => [['company_id' => $this->acme->id, 'tax_code_id' => $codes['VAT_STD']]]], $this->headersFor())
            ->assertCreated()->json('data.id');
        $basic = $this->postJson('/api/v1/tax-categories', ['name' => 'Basic food', 'codes' => [['company_id' => $this->acme->id, 'tax_code_id' => $codes['VAT_ZERO']]]], $this->headersFor())
            ->assertCreated()->json('data.id');

        $this->assertSame([$basic, $goods], $this->listIds('/api/v1/tax-categories', $this->headersFor()));
        $this->assertSame([$goods, $basic], $this->listIds('/api/v1/tax-categories?sort=-name', $this->headersFor()));
        $this->assertSame([$goods], $this->listIds('/api/v1/tax-categories?search=good', $this->headersFor()));
        $this->getJson('/api/v1/tax-categories?sort=codes', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get('/api/v1/tax-categories?format=csv', $this->headersFor())->assertOk());
        $this->assertSame(['Name', 'Used by', 'Default tax codes', 'Status', 'Updated'], $rows[0]);
        $this->assertSame(['Basic food', 'All companies', 'VAT_ZERO in Acme', 'Active'], array_slice($rows[1], 0, 4));
        $this->assertSame(['Goods', 'All companies', 'VAT_STD in Acme', 'Active'], array_slice($rows[2], 0, 4));

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.tax_category.export')->count()));
    }

    public function test_price_lists_search_sort_and_export(): void
    {
        $retail = $this->priceList('Retail', 'KES', default: true);
        $export = $this->priceList('Export', 'USD', false);
        $url = "/api/v1/companies/{$this->acme->id}/price-lists";

        $this->assertSame([$export, $retail], $this->listIds($url, $this->headersFor()));
        $this->assertSame([$retail, $export], $this->listIds("{$url}?sort=-name", $this->headersFor()));
        $this->assertSame([$retail, $export], $this->listIds("{$url}?sort=currency", $this->headersFor()));
        $this->assertSame([$export], $this->listIds("{$url}?search=usd", $this->headersFor()));
        $this->getJson("{$url}?sort=company_id", $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get("{$url}?format=csv", $this->headersFor())->assertOk());
        $this->assertSame(['Name', 'Currency', 'Prices', 'Default', 'Status', 'Updated'], $rows[0]);
        $this->assertSame(['Export', 'USD', 'Exclude tax'], array_slice($rows[1], 0, 3));
        $this->assertSame(['Retail', 'KES', 'Include tax', 'Yes', 'Active'], array_slice($rows[2], 0, 5));

        $fr = $this->csvRows($this->get("{$url}?format=csv&columns[]=prices&columns[]=default", [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Prix', 'Par défaut'], ['HT', 'Non'], ['TTC', 'Oui']], $fr);

        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'core.price_list.export')->count()));
    }

    public function test_an_export_needs_the_lists_view_permission_and_never_shows_another_tenant(): void
    {
        $this->priceList('Retail', 'KES');
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::company($this->acme->id)));

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->refusedExport("/api/v1/companies/{$this->acme->id}/tax-codes?format={$format}", $hr)->assertForbidden();
            $this->refusedExport("/api/v1/companies/{$this->acme->id}/price-lists?format={$format}", $hr)->assertForbidden();
            $this->refusedExport("/api/v1/tax-categories?format={$format}", $hr)->assertForbidden();
        }

        $other = $this->otherTenant();
        $this->refusedExport("/api/v1/companies/{$other['company']->id}/price-lists?format=csv", $this->headersFor())->assertNotFound();
        $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(TenantCurrencies::class)->activate('KES');
            PriceList::create(['company_id' => $other['company']->id, 'name' => 'Their prices', 'currency' => 'KES', 'tax_inclusive' => true]);
        });
        $text = implode("\n", array_merge(...$this->xlsxRows($this->get("/api/v1/companies/{$this->acme->id}/price-lists?format=xlsx", $this->headersFor())->assertOk())));
        $this->assertStringContainsString('Retail', $text);
        $this->assertStringNotContainsString('Their prices', $text);

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'core.tax_code.export')->count()));
    }
}
