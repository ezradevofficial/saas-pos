<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-02: each company has a base currency, locked after the first
// posting, and up to three reporting currencies.
class CompanyCurrencyApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            $tenantCurrencies = app(TenantCurrencies::class);
            $tenantCurrencies->provisionFor($this->acme);
            foreach (['EUR', 'GBP', 'CDF'] as $code) {
                $tenantCurrencies->activate($code);
            }
        });
    }

    private function url(?Company $company = null): string
    {
        return '/api/v1/companies/'.($company ?? $this->acme)->id.'/currencies';
    }

    public function test_reporting_currencies_default_to_none(): void
    {
        $this->getJson($this->url(), $this->headersFor())->assertOk()
            ->assertExactJson(['data' => [
                'base_currency' => 'KES',
                'base_currency_locked' => false,
                'base_currency_locked_at' => null,
                'reporting_currencies' => [],
            ]]);
    }

    public function test_the_owner_sets_the_base_and_up_to_three_reporting_currencies_in_order(): void
    {
        $this->putJson($this->url(), ['base_currency' => 'USD', 'reporting_currencies' => ['KES', 'EUR', 'CDF']], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.base_currency', 'USD')
            ->assertJsonPath('data.reporting_currencies', ['KES', 'EUR', 'CDF']);

        $this->inTenant(function () {
            $this->assertSame('USD', $this->acme->fresh()->base_currency);
            $this->assertSame(
                [[1, 'KES'], [2, 'EUR'], [3, 'CDF']],
                CompanyCurrency::where('company_id', $this->acme->id)->orderBy('position')->get()->map(fn ($c) => [$c->position, $c->code])->all(),
            );
            $entry = AuditEntry::where('action', 'core.company.currencies_update')->sole();
            $this->assertSame(['base_currency' => 'KES', 'reporting_currencies' => []], $entry->before);
            $this->assertSame(['base_currency' => 'USD', 'reporting_currencies' => ['KES', 'EUR', 'CDF']], $entry->after);
        });

        // Replaced, reordered.
        $this->putJson($this->url(), ['base_currency' => 'USD', 'reporting_currencies' => ['CDF']], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.reporting_currencies', ['CDF']);
        $this->getJson($this->url(), $this->headersFor())->assertJsonPath('data.reporting_currencies', ['CDF']);
    }

    public function test_more_than_three_reporting_currencies_are_refused(): void
    {
        $this->putJson($this->url(), ['base_currency' => 'KES', 'reporting_currencies' => ['USD', 'EUR', 'GBP', 'CDF']], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'too_many_reporting_currencies')
            ->assertJsonPath('message', __('core.currency.too_many_reporting_currencies', ['max' => 3]));

        $this->getJson($this->url(), $this->headersFor())->assertJsonPath('data.reporting_currencies', []);
    }

    public function test_currencies_must_be_active_in_the_tenant_distinct_and_not_the_base(): void
    {
        $this->putJson($this->url(), ['base_currency' => 'JPY', 'reporting_currencies' => ['CHF', 'USD', 'USD', 'KES']], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_currency', 'reporting_currencies.0', 'reporting_currencies.1', 'reporting_currencies.2']);

        $this->putJson($this->url(), ['base_currency' => 'KES', 'reporting_currencies' => ['KES']], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reporting_currencies.0']);

        $this->putJson($this->url(), [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_currency', 'reporting_currencies']);
    }

    public function test_the_base_currency_is_locked_after_the_first_posting(): void
    {
        $lock = app(BaseCurrencyLock::class);

        $this->inTenant(function () use ($lock) {
            $this->assertFalse($lock->isLocked($this->acme));
            $lock->lock($this->acme);
            $first = $this->acme->fresh()->base_currency_locked_at;
            $this->assertNotNull($first);
            $this->assertTrue($lock->isLocked($this->acme->fresh()));

            // Idempotent: the first posting's time stays.
            $this->travel(5)->minutes();
            $lock->lock($this->acme->fresh());
            $this->assertEquals($first, $this->acme->fresh()->base_currency_locked_at);
            $this->assertSame(1, AuditEntry::where('action', 'core.company.update')->where('auditable_id', $this->acme->id)->count());
        });

        $this->getJson($this->url(), $this->headersFor())->assertOk()
            ->assertJsonPath('data.base_currency_locked', true);

        $this->putJson($this->url(), ['base_currency' => 'USD', 'reporting_currencies' => []], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'base_currency_locked');

        // Through the company endpoint too.
        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['base_currency' => 'USD'], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'base_currency_locked');

        // Reporting currencies stay editable; the same base is accepted.
        $this->putJson($this->url(), ['base_currency' => 'KES', 'reporting_currencies' => ['USD']], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.reporting_currencies', ['USD']);
        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['base_currency' => 'KES', 'name' => 'Acme Renamed'], $this->headersFor())
            ->assertOk();
        $this->inTenant(fn () => $this->assertSame('KES', $this->acme->fresh()->base_currency));
    }

    public function test_changing_the_base_through_the_company_endpoint_activates_it_for_the_tenant(): void
    {
        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['base_currency' => 'TZS'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.base_currency', 'TZS');

        $this->getJson('/api/v1/tenant/currencies', $this->headersFor())->assertOk()
            ->assertJsonFragment(['code' => 'TZS', 'active' => true]);

        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['base_currency' => 'QQQ'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('base_currency');
    }

    public function test_scope_rules_404_out_of_scope_and_403_without_the_permission(): void
    {
        $other = $this->inTenant(fn () => $this->company('Other Co'));

        // Admin of Acme only: Other Co is not found.
        $acmeAdmin = $this->userWith('admin', Scope::company($this->acme->id));
        $this->getJson($this->url(), $this->headersFor($acmeAdmin))->assertOk();
        $this->putJson($this->url(), ['base_currency' => 'KES', 'reporting_currencies' => ['USD']], $this->headersFor($acmeAdmin))->assertOk();
        $this->getJson($this->url($other), $this->headersFor($acmeAdmin))->assertNotFound();
        $this->putJson($this->url($other), ['base_currency' => 'KES', 'reporting_currencies' => []], $this->headersFor($acmeAdmin))->assertNotFound();

        // An accountant sees the company and its currencies but cannot change them.
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->getJson($this->url(), $this->headersFor($accountant))->assertOk();
        $this->putJson($this->url(), ['base_currency' => 'KES', 'reporting_currencies' => []], $this->headersFor($accountant))->assertForbidden();

        // Another tenant's company does not exist here.
        $theirs = $this->otherTenant()['company'];
        $this->getJson($this->url($theirs), $this->headersFor())->assertNotFound();
        $this->putJson($this->url($theirs), ['base_currency' => 'KES', 'reporting_currencies' => []], $this->headersFor())->assertNotFound();
    }

    public function test_creating_a_company_activates_its_country_currencies(): void
    {
        $this->postJson('/api/v1/companies', ['name' => 'Acme Kin', 'country' => 'CD'], $this->headersFor())->assertCreated();

        $codes = collect($this->getJson('/api/v1/tenant/currencies', $this->headersFor())->json('data'))->keyBy('code');
        $this->assertTrue($codes->has('CDF'));
        // CDF was activated before (setUp) with the default rounding: an existing row is never overwritten.
        $this->assertSame(1, $codes['CDF']['cash_rounding_minor']);
    }
}
