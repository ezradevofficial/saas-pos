<?php

namespace Tests\Concerns;

use App\Core\Fiscal\FiscalSources;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRates;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fiscal\TestFiscalSource;

/**
 * The organisation with two company tax codes carrying fiscal bands (a
 * VAT code in band B at a test figure of 12.5 %, never a real rate, and
 * an exempt code in band A), the test document source registered, and
 * helpers for the company's fiscal settings.
 */
trait BuildsFiscal
{
    use BuildsOrganisation;

    protected TaxCode $vat;

    protected TaxCode $exempt;

    protected TaxCode $unbanded;

    protected function setUpFiscal(): void
    {
        $this->setUpOrganisation();
        TestFiscalSource::reset();
        app(FiscalSources::class)->register(TestFiscalSource::KEY, TestFiscalSource::class);

        $this->inTenant(function () {
            $this->vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_T', 'name' => 'VAT (test figure)', 'kind' => 'vat', 'fiscal_code' => 'B']);
            app(TaxRates::class)->add($this->vat, '12.5', CarbonImmutable::parse('2026-01-01'));
            $this->exempt = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_EX', 'name' => 'Exempt', 'kind' => 'exempt', 'fiscal_code' => 'A']);
            $this->unbanded = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_NB', 'name' => 'No band', 'kind' => 'vat', 'fiscal_code' => null]);
        });
    }

    protected function saveFiscalSettings(array $body, ?string $companyId = null): TestResponse
    {
        return $this->putJson('/api/v1/companies/'.($companyId ?? $this->acme->id).'/fiscal-settings', $body, $this->headersFor());
    }

    /** Transmission on with the fake driver. */
    protected function enableFakeFiscal(): void
    {
        $this->saveFiscalSettings(['driver' => 'fake', 'tin' => 'P051111111A', 'enabled' => true])->assertSuccessful();
    }
}
