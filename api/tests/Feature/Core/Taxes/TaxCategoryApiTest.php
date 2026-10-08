<?php

namespace Tests\Feature\Core\Taxes;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Sharing\MasterDataSetting;
use App\Core\MasterData\Taxes\ApplyCountryPack;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-03, TEN-08: tax categories, shared or per company, with a default
// tax code per company; codes shown only for companies the user reaches.
class TaxCategoryApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private Company $globex;

    private Branch $globexBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            $this->globex = $this->company('Globex');
            $this->globexBranch = $this->branch($this->globex, 'G');
            app(ApplyCountryPack::class)->apply($this->acme);
            app(ApplyCountryPack::class)->apply($this->globex);
        });
    }

    private function codeOf(Company $company, string $code = 'VAT_STD'): string
    {
        return $this->inTenant(fn () => TaxCode::where('company_id', $company->id)->where('code', $code)->value('id'));
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/tax-categories', $body, $headers ?? $this->headersFor());
    }

    public function test_a_shared_category_holds_a_default_code_per_company(): void
    {
        $response = $this->create(['name' => 'Standard goods', 'codes' => [
            ['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->acme)],
            ['company_id' => $this->globex->id, 'tax_code_id' => $this->codeOf($this->globex, 'VAT_ZERO')],
        ]])->assertCreated();

        $response->assertJsonPath('data.shared', true)->assertJsonPath('data.company_id', null)->assertJsonCount(2, 'data.codes');
        $id = $response->json('data.id');
        $codes = collect($response->json('data.codes'))->keyBy('company_id');
        $this->assertSame(['VAT_STD', 'VAT_ZERO'], [$codes[$this->acme->id]['code'], $codes[$this->globex->id]['code']]);

        // Change one company's default, clear another's; the rest stays.
        $this->patchJson("/api/v1/tax-categories/{$id}", ['name' => 'Goods', 'codes' => [
            ['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->acme, 'VAT_EXEMPT')],
        ]], $this->headersFor())->assertOk()->assertJsonPath('data.name', 'Goods')->assertJsonCount(2, 'data.codes');
        $after = $this->patchJson("/api/v1/tax-categories/{$id}", ['codes' => [
            ['company_id' => $this->globex->id, 'tax_code_id' => null],
        ]], $this->headersFor())->assertOk()->json('data.codes');
        $this->assertSame([[$this->acme->id, 'VAT_EXEMPT']], array_map(fn ($c) => [$c['company_id'], $c['code']], $after));

        $this->inTenant(function () use ($id) {
            $entries = AuditEntry::where('action', 'core.tax_category.codes_update')->where('auditable_id', $id)->orderBy('seq')->get();
            $this->assertCount(3, $entries);
            $this->assertSame([], $entries[0]->before['codes']);
            $this->assertSame([$this->acme->id => $this->codeOf($this->acme, 'VAT_EXEMPT')], $entries[2]->after['codes']);
        });
    }

    public function test_codes_must_be_active_codes_of_that_company(): void
    {
        // Another company's code.
        $this->create(['name' => 'X', 'codes' => [['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->globex)]]])
            ->assertUnprocessable()->assertJsonValidationErrors('codes.0.tax_code_id');

        // An archived code.
        $this->inTenant(fn () => TaxCode::findOrFail($this->codeOf($this->acme, 'VAT_WHT'))->archive());
        $this->create(['name' => 'X', 'codes' => [['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->acme, 'VAT_WHT')]]])
            ->assertUnprocessable()->assertJsonValidationErrors('codes.0.tax_code_id');

        // The same company twice, an unknown company.
        $this->create(['name' => 'X', 'codes' => [
            ['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->acme)],
            ['company_id' => $this->acme->id, 'tax_code_id' => null],
        ]])->assertUnprocessable()->assertJsonValidationErrors('codes.0.company_id');
        $this->create(['name' => 'X', 'codes' => [['company_id' => '01890000-0000-7000-8000-000000000000', 'tax_code_id' => null]]])
            ->assertUnprocessable()->assertJsonValidationErrors('codes.0.company_id');

        // A company's own category maps only that company (items kept per company, TEN-08).
        $this->itemsPerCompany();
        $this->create(['name' => 'Acme only', 'company_id' => $this->acme->id, 'codes' => [
            ['company_id' => $this->globex->id, 'tax_code_id' => $this->codeOf($this->globex)],
        ]])->assertUnprocessable()->assertJsonValidationErrors('codes.0.company_id');
        $this->create(['name' => 'Acme only', 'company_id' => $this->acme->id, 'codes' => [
            ['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->acme)],
        ]])->assertCreated()->assertJsonPath('data.shared', false);
    }

    public function test_visibility_and_permissions_follow_the_companies_reached(): void
    {
        // Built before any request: a request switches the default guard.
        $editor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Tax editor', ['core.tax.view', 'core.tax.edit']), Scope::company($this->acme->id));

            return $user;
        });

        $shared = $this->create(['name' => 'Shared', 'codes' => [
            ['company_id' => $this->acme->id, 'tax_code_id' => $this->codeOf($this->acme)],
            ['company_id' => $this->globex->id, 'tax_code_id' => $this->codeOf($this->globex)],
        ]])->json('data.id');
        // Company categories next to a shared one: stored directly, as the API
        // creates one kind or the other depending on the items mode (TEN-08).
        [$acmeOnly, $globexOnly] = $this->inTenant(fn () => [
            TaxCategory::create(['name' => 'Acme only', 'company_id' => $this->acme->id])->id,
            TaxCategory::create(['name' => 'Globex only', 'company_id' => $this->globex->id])->id,
        ]);

        // A Globex branch manager sees shared and Globex categories, and only Globex's default code.
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->globexBranch->id)));
        $list = $this->getJson('/api/v1/tax-categories', $manager)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$shared, $globexOnly], array_column($list, 'id'));
        $this->assertSame([$this->globex->id], array_column(collect($list)->firstWhere('id', $shared)['codes'], 'company_id'));
        $this->getJson("/api/v1/tax-categories/{$acmeOnly}", $manager)->assertNotFound();
        $this->getJson("/api/v1/tax-categories/{$shared}", $manager)->assertOk()->assertJsonCount(1, 'data.codes');

        // Viewing is not editing.
        $this->patchJson("/api/v1/tax-categories/{$shared}", ['name' => 'Mine'], $manager)->assertForbidden();
        $this->patchJson("/api/v1/tax-categories/{$globexOnly}", ['name' => 'Mine'], $manager)->assertForbidden();
        $this->create(['name' => 'New'], $manager)->assertForbidden();
        $this->create(['name' => 'New', 'company_id' => $this->globex->id], $manager)->assertForbidden();
        $this->create(['name' => 'New', 'company_id' => $this->acme->id], $manager)->assertNotFound();

        // A company-scoped tax editor manages that company's categories, not shared ones,
        // and cannot set another company's default in a shared one.
        $this->itemsPerCompany();
        $editorHeaders = $this->headersFor($editor);
        $this->create(['name' => 'Acme services', 'company_id' => $this->acme->id], $editorHeaders)->assertCreated();
        $this->create(['name' => 'Shared new'], $editorHeaders)->assertForbidden();
        $this->patchJson("/api/v1/tax-categories/{$shared}", ['codes' => [['company_id' => $this->acme->id, 'tax_code_id' => null]]], $editorHeaders)->assertForbidden();
        $this->postJson("/api/v1/tax-categories/{$acmeOnly}/archive", [], $editorHeaders)->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->postJson("/api/v1/tax-categories/{$acmeOnly}/restore", [], $editorHeaders)->assertOk()->assertJsonPath('data.archived_at', null);

        // A cashier holds no tax permission.
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson('/api/v1/tax-categories', $cashier)->assertForbidden();
        $this->getJson("/api/v1/tax-categories/{$shared}", $cashier)->assertNotFound();
    }

    /** TEN-08: items (and so tax categories) kept per company, set directly. */
    private function itemsPerCompany(): void
    {
        $this->inTenant(fn () => MasterDataSetting::create(['data_type' => 'items', 'mode' => 'per_company', 'changed_at' => now()]));
    }

    public function test_archived_categories_are_listed_on_request(): void
    {
        $id = $this->create(['name' => 'Old'])->json('data.id');
        $this->postJson("/api/v1/tax-categories/{$id}/archive", [], $this->headersFor())->assertOk();

        $this->getJson('/api/v1/tax-categories', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tax-categories?status=archived', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.tax_category.archive')->count()));
    }
}
