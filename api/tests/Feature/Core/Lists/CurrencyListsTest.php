<?php

namespace Tests\Feature\Core\Lists;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Lists and pickers plan, task 3: sort and export (EXP-01) on a company's
// rate history (CUR-03) and the tenant's currencies (CUR-01), audited
// (AUD-01), never another tenant's rows (TEN-01).
class CurrencyListsTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();
    }

    private function url(string $query = ''): string
    {
        return "/api/v1/companies/{$this->acme->id}/exchange-rates{$query}";
    }

    public function test_the_rate_history_sorts_both_ways(): void
    {
        $old = $this->rate('USD', 'CDF', '2850.5', 'shop', '-2 days', ['buy' => '2840', 'sell' => '2860'])->id;
        $new = $this->rate('USD', 'CDF', '2800', 'reference', '-1 hour')->id;

        // Newest first by default.
        $this->assertSame([$new, $old], $this->listIds($this->url(), $this->headersFor()));
        $this->assertSame([$old, $new], $this->listIds($this->url('?sort=effective_at'), $this->headersFor()));
        $this->assertSame([$new, $old], $this->listIds($this->url('?sort=mid'), $this->headersFor()));
        $this->assertSame([$old, $new], $this->listIds($this->url('?sort=-mid'), $this->headersFor()));
        // Rates without a buy rate last either way.
        $this->assertSame([$old, $new], $this->listIds($this->url('?sort=-buy'), $this->headersFor()));
        $this->getJson($this->url('?sort=entered_by'), $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_the_rate_history_exports_in_the_companys_time_zone(): void
    {
        $this->inTenant(fn () => ExchangeRate::create([
            'company_id' => $this->acme->id, 'base' => 'USD', 'quote' => 'CDF', 'kind' => 'shop', 'mid' => '2850.5', 'buy' => '2840',
            'effective_at' => CarbonImmutable::parse('2026-10-08 21:30:00', 'UTC'), 'source' => 'manual',
        ]));
        // A feed without a name of its own reads "Rate feed".
        $this->rate('CDF', 'USD', '0.00035', 'reference', '2026-10-01 08:00:00');

        $response = $this->get($this->url('?format=csv&sort=effective_at'), $this->headersFor())->assertOk();
        $this->assertMatchesRegularExpression('/attachment; filename=exchange-rates-\d{4}-\d{2}-\d{2}\.csv/', $response->headers->get('Content-Disposition'));
        $rows = $this->csvRows($response);
        $this->assertSame(['Currency pair', 'Effective', 'Kind', 'Rate', 'Buy', 'Sell', 'Direction', 'Source', 'Entered'], $rows[0]);
        $this->assertSame(['CDF/USD', '1 Oct 2026, 11:00', 'Reference', '0.00035', '', '', 'As listed', 'Rate feed'], array_slice($rows[1], 0, 8));
        // 21:30 UTC is 00:30 the next day in Nairobi.
        $this->assertSame(['USD/CDF', '9 Oct 2026, 00:30', 'Shop', '2,850.5', '2,840', '', 'As listed', 'Entered by hand'], array_slice($rows[2], 0, 8));

        // With ?pair=, each row says whether it is stored the other way; French values.
        $fr = $this->csvRows($this->get($this->url('?format=csv&pair=USD/CDF&sort=effective_at&columns[]=pair&columns[]=mid&columns[]=direction'), [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Paire de devises', 'Taux', 'Sens'], ['CDF/USD', '0,00035', 'Sens inverse'], ['USD/CDF', "2\u{202F}850,5", 'Dans ce sens']], $fr);

        // The filters apply to the export.
        $this->assertSame([['Rate'], ['2,850.5']], $this->csvRows($this->get($this->url('?format=csv&kind=shop&columns[]=mid'), $this->headersFor())));

        $this->inTenant(fn () => $this->assertSame(3, AuditEntry::where('action', 'core.exchange_rate.export')->count()));
    }

    public function test_the_tenant_currencies_search_sort_page_and_export(): void
    {
        $this->inTenant(function () {
            app(TenantCurrencies::class)->activate('KES');
            TenantCurrency::where('code', 'KES')->update(['active' => false]);
        });
        $codes = fn (string $query, array $headers = []) => array_column($this->getJson("/api/v1/tenant/currencies{$query}", [...$this->headersFor(), ...$headers])->assertOk()->json('data'), 'code');

        $this->assertSame(['CDF', 'KES', 'USD'], $codes(''));
        $this->assertSame(['USD', 'KES', 'CDF'], $codes('?sort=-code'));
        // Switched-on currencies first.
        $this->assertSame('KES', $codes('?sort=status')[2]);
        $this->assertSame('KES', $codes('?sort=-status')[0]);
        $this->assertSame(['CDF'], $codes('?search=congo'));
        $this->assertSame(['CDF'], $codes('?search=franc+congolais', ['Accept-Language' => 'fr']));
        $this->assertSame(['USD'], $codes('?search=usd'));
        $this->getJson('/api/v1/tenant/currencies?sort=name', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        // Every currency without paging parameters (pickers fetch the whole list); pages with them.
        $this->assertNull($this->getJson('/api/v1/tenant/currencies', $this->headersFor())->json('meta'));
        $page = $this->getJson('/api/v1/tenant/currencies?per_page=2&page=2', $this->headersFor())->assertOk();
        $this->assertSame(['USD'], array_column($page->json('data'), 'code'));
        $this->assertSame(3, $page->json('meta.total'));

        $rows = $this->csvRows($this->get('/api/v1/tenant/currencies?format=csv', $this->headersFor())->assertOk());
        $this->assertSame(['Code', 'Currency', 'Decimals', 'Cash rounding', 'Status', 'Updated'], $rows[0]);
        $this->assertSame(['CDF', 'Congolese Franc', '0', 'CDF 50', 'On'], array_slice($rows[1], 0, 5));
        $this->assertCount(4, $rows);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.currency.export')->count()));
    }

    public function test_an_export_needs_the_lists_view_permission_and_never_shows_another_tenant(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 hour');
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::company($this->acme->id)));

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->refusedExport($this->url("?format={$format}"), $hr)->assertForbidden();
            $this->refusedExport("/api/v1/tenant/currencies?format={$format}", $hr)->assertForbidden();
        }

        $other = $this->otherTenant();
        $this->refusedExport("/api/v1/companies/{$other['company']->id}/exchange-rates?format=csv", $this->headersFor())->assertNotFound();
        $this->asTenant($other['user']->tenant_id, fn () => app(TenantCurrencies::class)->activate('EUR'));
        $text = implode("\n", array_merge(...$this->xlsxRows($this->get('/api/v1/tenant/currencies?format=xlsx', $this->headersFor())->assertOk())));
        $this->assertStringContainsString('CDF', $text);
        $this->assertStringNotContainsString('EUR', $text);

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'core.exchange_rate.export')->count()));
    }
}
