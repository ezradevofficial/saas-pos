<?php

namespace Tests\Feature\Core\CountryPacks;

use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CP-01, CP-03: the published packs, read-only, for holders of core.tax.view.
class CountryPackApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_the_packs_and_their_codes_are_listed_with_rates_needed(): void
    {
        $this->getJson('/api/v1/country-packs', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.code', 'CD')
            ->assertJsonPath('data.1.code', 'KE')
            ->assertJsonPath('data.1.version', 1)
            ->assertJsonPath('data.1.needs_confirmation', [])
            ->assertJsonMissingPath('data.1.tax_codes');

        $ke = $this->getJson('/api/v1/country-packs/KE', $this->headersFor())->assertOk();
        $ke->assertJsonPath('data.name', 'Kenya')->assertJsonPath('meta.versions.0.version', 1);
        $this->assertNotEmpty($ke->json('data.sources'));
        // CP-01: the standard rate comes from public research; so does the withholding rate since 2026-10-22.
        $std = collect($ke->json('data.tax_codes'))->firstWhere('code', 'VAT_STD');
        $this->assertSame(['16.0000', false, null], [$std['rate'], $std['needs_confirmation'], $std['fiscal_code']]);
        $wht = collect($ke->json('data.tax_codes'))->firstWhere('code', 'VAT_WHT');
        $this->assertSame(['2.0000', false, 'medium'], [$wht['rate'], $wht['needs_confirmation'], $wht['research']['confidence']]);

        $this->getJson('/api/v1/country-packs/CD', $this->headersFor() + ['Accept-Language' => 'fr'])->assertOk()
            ->assertJsonPath('data.name', 'République démocratique du Congo');
        $this->getJson('/api/v1/country-packs/ZZ', $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/country-packs/ke', $this->headersFor())->assertNotFound();
    }

    public function test_readers_need_a_tax_permission(): void
    {
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->getJson('/api/v1/country-packs/KE', $manager)->assertOk();

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson('/api/v1/country-packs', $cashier)->assertForbidden();
        $this->getJson('/api/v1/country-packs/KE', $cashier)->assertForbidden();
    }
}
