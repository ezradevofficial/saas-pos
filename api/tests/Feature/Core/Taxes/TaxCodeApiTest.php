<?php

namespace Tests\Feature\Core\Taxes;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-03, CP-01, CP-02: tax codes copied from the country pack, the
// tenant's own codes, effective-dated rates, archive, permissions.
// Percentages here are test inputs, not real-world rates.
class TaxCodeApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function codes(?string $companyId = null, string $query = ''): array
    {
        return $this->getJson('/api/v1/companies/'.($companyId ?? $this->acme->id).'/tax-codes'.$query, $this->headersFor())
            ->assertOk()->json('data');
    }

    private function codeId(string $code, ?string $companyId = null): string
    {
        return collect($this->codes($companyId))->firstWhere('code', $code)['id'];
    }

    private function applyPack(?string $companyId = null): array
    {
        return $this->postJson('/api/v1/companies/'.($companyId ?? $this->acme->id).'/tax-codes/apply-pack', [], $this->headersFor())
            ->assertOk()->json('data');
    }

    public function test_a_company_created_through_the_api_gets_its_country_pack_codes(): void
    {
        $ke = $this->postJson('/api/v1/companies', ['name' => 'Nairobi Ltd', 'country' => 'KE'], $this->headersFor())->assertCreated()->json('data.id');
        $cd = $this->postJson('/api/v1/companies', ['name' => 'Kinshasa SARL', 'country' => 'CD'], $this->headersFor())->assertCreated()->json('data.id');

        $codes = collect($this->codes($ke))->keyBy('code');
        $this->assertSame(['VAT_EXEMPT', 'VAT_STD', 'VAT_WHT', 'VAT_ZERO'], $codes->keys()->all());
        $this->assertSame(['VAT_EXEMPT', 'VAT_STD', 'VAT_ZERO'], collect($this->codes($cd))->pluck('code')->all());

        // The unconfirmed standard rate is copied as needed, never filled in.
        $std = $codes['VAT_STD'];
        $this->assertSame(['vat', 'VAT_STD', null, true], [$std['kind'], $std['pack_code'], $std['current_rate']['rate'], $std['rate_needed']]);
        $this->assertSame([['rate' => null, 'effective_from' => '2026-01-01', 'effective_to' => null, 'needs_confirmation' => true, 'source' => 'pack']], array_map(fn ($r) => array_diff_key($r, ['id' => 1]), $std['rates']));
        $this->assertSame(['0.0000', false], [$codes['VAT_ZERO']['current_rate']['rate'], $codes['VAT_ZERO']['rate_needed']]);
        $this->assertSame([[], null, false], [$codes['VAT_EXEMPT']['rates'], $codes['VAT_EXEMPT']['current_rate'], $codes['VAT_EXEMPT']['rate_needed']]);
        $this->assertSame('TVA, taux normal', $std['name_fr']);

        $this->inTenant(fn () => $this->assertSame(4, AuditEntry::where('action', 'core.tax_code.create')->where('after->company_id', $ke)->count()));
    }

    public function test_apply_pack_adds_only_missing_codes_and_never_overwrites_tenant_edits(): void
    {
        // Companies made through the model (not the API) have no codes yet.
        $this->assertSame([], $this->codes());

        $first = $this->applyPack();
        $this->assertSame(['KE', 1, [], []], [$first['pack'], $first['version'], array_diff(['VAT_EXEMPT', 'VAT_STD', 'VAT_WHT', 'VAT_ZERO'], $first['added']), $first['skipped']]);
        $this->assertCount(4, $first['added']);

        // The tenant renames one, sets a rate, archives another.
        $std = $this->codeId('VAT_STD');
        $this->patchJson("/api/v1/tax-codes/{$std}", ['name_en' => 'VAT', 'code' => 'vat'], $this->headersFor())->assertOk()->assertJsonPath('data.code', 'VAT');
        $this->postJson("/api/v1/tax-codes/{$std}/rates", ['rate' => '12.5', 'effective_from' => '2026-01-01'], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/tax-codes/'.$this->codeId('VAT_WHT').'/archive', [], $this->headersFor())->assertOk();

        $again = $this->applyPack();
        $this->assertSame([[], []], [$again['added'], $again['skipped']]);

        $this->inTenant(function () use ($std) {
            $code = TaxCode::findOrFail($std);
            $this->assertSame(['VAT', 'VAT', 'VAT_STD'], [$code->code, $code->name_en, $code->pack_code]);
            $this->assertSame(['12.5000'], $code->rates()->pluck('rate')->all());
            $this->assertSame(4, TaxCode::where('company_id', $this->acme->id)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.tax_code.apply_pack')->count());
        });
    }

    public function test_apply_pack_skips_a_pack_code_the_tenant_already_uses(): void
    {
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", [
            'code' => 'vat_zero', 'name_en' => 'Own zero', 'name_fr' => 'Zéro maison', 'kind' => 'zero_rated', 'effective_from' => '2026-01-01',
        ], $this->headersFor())->assertCreated()->assertJsonPath('data.code', 'VAT_ZERO')->assertJsonPath('data.pack_code', null);

        $result = $this->applyPack();
        $this->assertSame(['VAT_ZERO'], $result['skipped']);
        $this->assertNotContains('VAT_ZERO', $result['added']);
        $this->assertSame('Own zero', collect($this->codes())->firstWhere('code', 'VAT_ZERO')['name_en']);
    }

    public function test_the_tenant_creates_its_own_codes(): void
    {
        $response = $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", [
            'code' => ' excise_x ', 'name_en' => 'Excise X', 'name_fr' => 'Accise X', 'kind' => 'excise', 'rate' => '10', 'effective_from' => '2026-03-01', 'fiscal_code' => 'E',
        ], $this->headersFor())->assertCreated();

        $response->assertJsonPath('data.code', 'EXCISE_X')
            ->assertJsonPath('data.current_rate.rate', '10.0000')
            ->assertJsonPath('data.current_rate.needs_confirmation', false)
            ->assertJsonPath('data.rate_needed', false)
            ->assertJsonPath('data.fiscal_code', 'E');

        // Without a rate: "Rate needed".
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", [
            'code' => 'WHT2', 'name_en' => 'Withholding', 'name_fr' => 'Retenue', 'kind' => 'withholding', 'effective_from' => '2026-01-01',
        ], $this->headersFor())->assertCreated()->assertJsonPath('data.rate_needed', true)->assertJsonPath('data.current_rate.needs_confirmation', true);

        // Exempt: no rate rows.
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", [
            'code' => 'EX', 'name_en' => 'Exempt', 'name_fr' => 'Exonéré', 'kind' => 'exempt',
        ], $this->headersFor())->assertCreated()->assertJsonPath('data.rates', []);

        // Codes are unique among the company's active codes, whatever the case.
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", [
            'code' => 'Excise_X', 'name_en' => 'Again', 'name_fr' => 'Encore', 'kind' => 'vat', 'effective_from' => '2026-01-01',
        ], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');

        foreach ([
            ['kind' => 'exempt', 'rate' => '5'],
            ['kind' => 'zero_rated', 'rate' => '5', 'effective_from' => '2026-01-01'],
            ['kind' => 'vat', 'rate' => '100.5', 'effective_from' => '2026-01-01'],
            ['kind' => 'vat', 'rate' => '12.50001', 'effective_from' => '2026-01-01'],
            ['kind' => 'vat', 'rate' => '12.5'],
            ['kind' => 'sales_tax', 'effective_from' => '2026-01-01'],
        ] as $invalid) {
            $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", ['code' => 'BAD', 'name_en' => 'Bad', 'name_fr' => 'Mauvais', ...$invalid], $this->headersFor())
                ->assertUnprocessable();
        }
    }

    public function test_a_new_rate_closes_the_previous_one_and_history_is_never_rewritten(): void
    {
        $this->applyPack();
        $std = $this->codeId('VAT_STD');
        $url = "/api/v1/tax-codes/{$std}/rates";

        // The still-needed rate is filled in for its own start date.
        $this->postJson($url, ['rate' => '12', 'effective_from' => '2026-01-01'], $this->headersFor())->assertCreated()
            ->assertJsonPath('data.rate_needed', false)
            ->assertJsonCount(1, 'data.rates');

        // A later rate closes it the day before.
        $rates = $this->postJson($url, ['rate' => '14', 'effective_from' => '2027-07-01'], $this->headersFor())->assertCreated()->json('data.rates');
        $this->assertSame([
            ['12.0000', '2026-01-01', '2027-06-30'],
            ['14.0000', '2027-07-01', null],
        ], array_map(fn ($r) => [$r['rate'], $r['effective_from'], $r['effective_to']], $rates));

        // On or before the latest start: refused.
        foreach (['2027-07-01', '2027-03-01', '2025-01-01'] as $date) {
            $this->postJson($url, ['rate' => '15', 'effective_from' => $date], $this->headersFor())
                ->assertUnprocessable()->assertJsonPath('code', 'tax_rate_overlap');
        }

        // Zero-rated stays 0; exempt takes no rate.
        $this->postJson('/api/v1/tax-codes/'.$this->codeId('VAT_ZERO').'/rates', ['rate' => '1', 'effective_from' => '2027-01-01'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->postJson('/api/v1/tax-codes/'.$this->codeId('VAT_EXEMPT').'/rates', ['rate' => '0', 'effective_from' => '2027-01-01'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'tax_code_exempt');

        $this->inTenant(function () use ($std) {
            $this->assertSame(2, TaxRate::where('tax_code_id', $std)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.tax_rate.create')->where('after->rate', '14.0000')->count());
            $close = AuditEntry::where('action', 'core.tax_rate.update')->where('after->effective_to', '!=', null)->get();
            $this->assertCount(1, $close);
        });
    }

    public function test_a_period_still_awaiting_confirmation_is_confirmed_on_its_own_start_date(): void
    {
        $this->applyPack();
        $std = $this->codeId('VAT_STD');

        // A figure was entered but is still marked for confirmation (as a pack may ship it).
        $this->inTenant(fn () => TaxRate::where('tax_code_id', $std)->sole()->fill(['rate' => '10', 'needs_confirmation' => true])->save());
        $this->getJson("/api/v1/tax-codes/{$std}", $this->headersFor())->assertJsonPath('data.rate_needed', true);

        $rates = $this->postJson("/api/v1/tax-codes/{$std}/rates", ['rate' => '12.5', 'effective_from' => '2026-01-01'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.rate_needed', false)->json('data.rates');
        $this->assertSame([['12.5000', '2026-01-01', null, false, 'tenant']], array_map(fn ($r) => [$r['rate'], $r['effective_from'], $r['effective_to'], $r['needs_confirmation'], $r['source']], $rates));

        // Once confirmed, the same date is history: refused.
        $this->postJson("/api/v1/tax-codes/{$std}/rates", ['rate' => '10', 'effective_from' => '2026-01-01'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'tax_rate_overlap');
    }

    public function test_an_archived_code_takes_no_new_rate(): void
    {
        $this->applyPack();
        $std = $this->codeId('VAT_STD');
        $this->postJson("/api/v1/tax-codes/{$std}/archive", [], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/tax-codes/{$std}/rates", ['rate' => '12.5', 'effective_from' => '2027-01-01'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'tax_code_archived');
        $this->inTenant(fn () => $this->assertSame(1, TaxRate::where('tax_code_id', $std)->count()));

        $this->postJson("/api/v1/tax-codes/{$std}/restore", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/tax-codes/{$std}/rates", ['rate' => '12.5', 'effective_from' => '2027-01-01'], $this->headersFor())->assertCreated();
    }

    public function test_archive_and_restore(): void
    {
        $this->applyPack();
        $wht = $this->codeId('VAT_WHT');

        $this->postJson("/api/v1/tax-codes/{$wht}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.code', 'VAT_WHT');
        $this->assertNotContains('VAT_WHT', array_column($this->codes(), 'code'));
        $this->assertContains('VAT_WHT', array_column($this->codes(query: '?status=archived'), 'code'));

        // Its code is free again; restoring then conflicts.
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes", [
            'code' => 'VAT_WHT', 'name_en' => 'New', 'name_fr' => 'Nouveau', 'kind' => 'withholding', 'effective_from' => '2026-01-01',
        ], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/tax-codes/{$wht}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->patchJson('/api/v1/tax-codes/'.$this->codeId('VAT_WHT'), ['code' => 'WHT_NEW'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/tax-codes/{$wht}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.tax_code.archive')->count()));
    }

    public function test_scope_and_permissions(): void
    {
        $this->applyPack();
        $std = $this->codeId('VAT_STD');

        // A branch manager reads the codes of their branch's company, but cannot change them.
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->getJson("/api/v1/companies/{$this->acme->id}/tax-codes", $manager)->assertOk()->assertJsonCount(4, 'data');
        $this->getJson("/api/v1/tax-codes/{$std}", $manager)->assertOk();
        $this->postJson("/api/v1/tax-codes/{$std}/rates", ['rate' => '1', 'effective_from' => '2027-01-01'], $manager)->assertForbidden();
        $this->patchJson("/api/v1/tax-codes/{$std}", ['name_en' => 'X'], $manager)->assertForbidden();
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes/apply-pack", [], $manager)->assertForbidden();
        $this->postJson("/api/v1/tax-codes/{$std}/archive", [], $manager)->assertForbidden();

        // A cashier holds no tax permission and does not see the company: not found.
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/companies/{$this->acme->id}/tax-codes", $cashier)->assertNotFound();
        $this->getJson("/api/v1/tax-codes/{$std}", $cashier)->assertNotFound();

        // Another tenant's owner: not found.
        $other = $this->otherTenant();
        $otherHeaders = $this->bearer($this->tokenFor($other['user']));
        $this->getJson("/api/v1/tax-codes/{$std}", $otherHeaders)->assertNotFound();
        $this->postJson("/api/v1/companies/{$this->acme->id}/tax-codes/apply-pack", [], $otherHeaders)->assertNotFound();

        $this->inTenant(fn () => $this->assertSame(4, TaxCode::count()));
    }

    public function test_nothing_is_added_under_an_archived_company(): void
    {
        $closed = $this->inTenant(function () {
            $company = $this->company('Closed');
            $company->archive();

            return $company;
        });

        $this->postJson("/api/v1/companies/{$closed->id}/tax-codes/apply-pack", [], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'parent_archived');
        $this->postJson("/api/v1/companies/{$closed->id}/tax-codes", [
            'code' => 'X', 'name_en' => 'X', 'name_fr' => 'X', 'kind' => 'exempt',
        ], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'parent_archived');
    }
}
