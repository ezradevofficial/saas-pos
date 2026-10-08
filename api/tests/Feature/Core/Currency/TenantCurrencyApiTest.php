<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\CurrencyUsage;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-01: the tenant activates currencies and sets their cash rounding;
// decimals change only while no amount in the currency is stored.
class TenantCurrencyApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->provisionFor($this->acme));
    }

    private function currency(string $code): TenantCurrency
    {
        return $this->inTenant(fn () => TenantCurrency::where('code', $code)->sole());
    }

    public function test_a_kenyan_company_activates_kes_and_usd(): void
    {
        $response = $this->getJson('/api/v1/tenant/currencies', $this->headersFor())->assertOk();

        $this->assertSame(['KES', 'USD'], collect($response->json('data'))->pluck('code')->all());
        $kes = collect($response->json('data'))->firstWhere('code', 'KES');
        $this->assertSame('Kenyan Shilling', $kes['name']);
        $this->assertSame(2, $kes['decimals']);
        $this->assertSame(2, $kes['default_decimals']);
        $this->assertSame(1, $kes['cash_rounding_minor']);
        $this->assertTrue($kes['active']);
        $this->assertFalse($kes['decimals_locked']);
    }

    public function test_the_owner_activates_cdf_with_its_zero_decimals_and_a_cash_rounding(): void
    {
        $response = $this->postJson('/api/v1/tenant/currencies', ['code' => 'CDF', 'cash_rounding_minor' => 50], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.code', 'CDF')
            ->assertJsonPath('data.decimals', 0)
            ->assertJsonPath('data.cash_rounding_minor', 50)
            ->assertJsonPath('data.active', true);

        $id = $response->json('data.id');
        $this->inTenant(function () use ($id) {
            $this->assertTrue(AuditEntry::where('action', 'core.currency.create')->where('auditable_id', $id)->exists());
            $this->assertSame(0, app(CurrencyDecimals::class)->for('CDF'));
        });
    }

    public function test_activation_validates_the_code(): void
    {
        foreach (['QQQ', 'kes', 'ZWD'] as $code) {
            $this->postJson('/api/v1/tenant/currencies', ['code' => $code], $this->headersFor())
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }

        // Already in the tenant's list: reactivate it with PATCH instead.
        $this->postJson('/api/v1/tenant/currencies', ['code' => 'KES'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/tenant/currencies', ['code' => 'EUR', 'cash_rounding_minor' => 0, 'decimals' => 9], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['cash_rounding_minor', 'decimals']);
    }

    public function test_cash_rounding_and_decimals_are_editable_while_unused(): void
    {
        $usd = $this->currency('USD');

        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['cash_rounding_minor' => 5, 'decimals' => 3], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.cash_rounding_minor', 5)
            ->assertJsonPath('data.decimals', 3);

        $this->inTenant(function () use ($usd) {
            $this->assertSame(3, app(CurrencyDecimals::class)->for('USD'), 'the tenant value overrides the catalogue');
            $entry = AuditEntry::where('action', 'core.currency.update')->where('auditable_id', $usd->id)->sole();
            $this->assertEquals(['decimals' => 3, 'cash_rounding_minor' => 5], $entry->after);
        });
    }

    public function test_decimals_lock_once_an_amount_in_the_currency_is_stored(): void
    {
        $usage = app(CurrencyUsage::class);
        $this->assertFalse($usage->isUsed('USD'));

        // A module (sales, accounting...) reports stored amounts.
        $usage->register(fn (string $code) => $code === 'USD');
        $this->assertTrue($usage->isUsed('USD'));
        $this->assertFalse($usage->isUsed('KES'));

        $usd = $this->currency('USD');

        $this->getJson('/api/v1/tenant/currencies', $this->headersFor())->assertOk()
            ->assertJsonPath('data.1.code', 'USD')
            ->assertJsonPath('data.1.decimals_locked', true);

        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['decimals' => 3], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'currency_decimals_locked');
        $this->assertSame(2, $this->currency('USD')->decimals);

        // The same value, and the other settings, are still accepted.
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['decimals' => 2, 'cash_rounding_minor' => 25], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.cash_rounding_minor', 25);
    }

    public function test_a_currency_used_by_a_company_cannot_be_deactivated(): void
    {
        $kes = $this->currency('KES');

        foreach ([false, 0, '0'] as $inactive) {
            $this->patchJson("/api/v1/tenant/currencies/{$kes->id}", ['active' => $inactive], $this->headersFor())
                ->assertUnprocessable()
                ->assertJsonPath('code', 'currency_in_use');
        }

        $this->putJson("/api/v1/companies/{$this->acme->id}/currencies", ['base_currency' => 'KES', 'reporting_currencies' => ['USD']], $this->headersFor())->assertOk();
        $usd = $this->currency('USD');
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['active' => false], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'currency_in_use');

        $this->putJson("/api/v1/companies/{$this->acme->id}/currencies", ['base_currency' => 'KES', 'reporting_currencies' => []], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['active' => false], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.active', false);
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['active' => true], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }

    public function test_editing_needs_the_edit_permission_at_tenant_scope(): void
    {
        $usd = $this->currency('USD');

        // Admin at company scope holds core.currency.edit, but not tenant-wide.
        $companyAdmin = $this->userWith('admin', Scope::company($this->acme->id));
        $this->getJson('/api/v1/tenant/currencies', $this->headersFor($companyAdmin))->assertOk();
        $this->postJson('/api/v1/tenant/currencies', ['code' => 'EUR'], $this->headersFor($companyAdmin))->assertForbidden();
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['cash_rounding_minor' => 5], $this->headersFor($companyAdmin))->assertForbidden();

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/tenant/currencies', $this->headersFor($manager))->assertOk();
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['cash_rounding_minor' => 5], $this->headersFor($manager))->assertForbidden();

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/tenant/currencies', $this->headersFor($cashier))->assertForbidden();

        $tenantAdmin = $this->userWith('admin', Scope::tenant());
        $this->patchJson("/api/v1/tenant/currencies/{$usd->id}", ['cash_rounding_minor' => 5], $this->headersFor($tenantAdmin))->assertOk();
    }

    public function test_another_tenants_currency_is_not_found(): void
    {
        $other = $this->otherTenant();
        $theirs = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(TenantCurrencies::class)->provisionFor($other['company']);

            return TenantCurrency::where('code', 'USD')->sole();
        });

        $this->patchJson("/api/v1/tenant/currencies/{$theirs->id}", ['cash_rounding_minor' => 5], $this->headersFor())->assertNotFound();
        $this->assertSame(1, $this->asTenant($other['user']->tenant_id, fn () => $theirs->fresh()->cash_rounding_minor));
    }
}
