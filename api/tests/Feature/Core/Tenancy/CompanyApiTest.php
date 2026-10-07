<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-03 companies, TEN-06 archive/restore, RBAC-04 scoped visibility.
class CompanyApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_the_owner_creates_a_kenyan_company_with_country_defaults(): void
    {
        $response = $this->postJson('/api/v1/companies', [
            'name' => 'Acme Coast',
            'country' => 'KE',
        ], $this->headersFor())->assertCreated();

        $response->assertJsonPath('data.name', 'Acme Coast')
            ->assertJsonPath('data.legal_name', 'Acme Coast')
            ->assertJsonPath('data.country', 'KE')
            ->assertJsonPath('data.base_currency', 'KES')
            ->assertJsonPath('data.timezone', 'Africa/Nairobi')
            ->assertJsonPath('data.fiscal_year_start_month', 1)
            ->assertJsonPath('data.archived_at', null);

        $id = $response->json('data.id');
        $this->inTenant(function () use ($id) {
            $this->assertSame('KES', Company::findOrFail($id)->base_currency);
            $this->assertTrue(AuditEntry::where('action', 'core.company.create')->where('auditable_id', $id)->exists());
        });
    }

    public function test_a_congolese_company_defaults_to_usd(): void
    {
        $this->postJson('/api/v1/companies', ['name' => 'Acme Kin', 'country' => 'CD'], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.base_currency', 'USD')
            ->assertJsonPath('data.timezone', 'Africa/Kinshasa');
    }

    public function test_create_validates_country_currency_and_timezone(): void
    {
        $this->postJson('/api/v1/companies', [
            'name' => 'Bad',
            'country' => 'UG',
            'base_currency' => 'kes',
            'timezone' => 'Mars/Olympus',
            'fiscal_year_start_month' => 13,
        ], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['country', 'base_currency', 'timezone', 'fiscal_year_start_month']);
    }

    public function test_the_owner_lists_shows_and_updates_companies(): void
    {
        $this->getJson('/api/v1/companies', $this->headersFor())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->acme->id);

        $this->getJson("/api/v1/companies/{$this->acme->id}", $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme');

        $this->patchJson("/api/v1/companies/{$this->acme->id}", [
            'name' => 'Acme Group',
            'tax_id' => 'P051234567X',
        ], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Group')
            ->assertJsonPath('data.tax_id', 'P051234567X');
    }

    public function test_archive_and_restore(): void
    {
        $second = $this->postJson('/api/v1/companies', ['name' => 'Second', 'country' => 'KE'], $this->headersFor())
            ->json('data.id');

        $this->postJson("/api/v1/companies/{$second}/archive", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.id', $second);

        $this->assertNotNull($this->getJson("/api/v1/companies/{$second}", $this->headersFor())->json('data.archived_at'));

        // Archived records leave the default list and stay in ?status=all.
        $this->getJson('/api/v1/companies', $this->headersFor())->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/companies?status=all', $this->headersFor())->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/companies?status=archived', $this->headersFor())
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second);

        $this->postJson("/api/v1/companies/{$second}/restore", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        $this->inTenant(function () use ($second) {
            $this->assertTrue(AuditEntry::where('action', 'core.company.archive')->where('auditable_id', $second)->exists());
            $this->assertTrue(AuditEntry::where('action', 'core.company.restore')->where('auditable_id', $second)->exists());
        });
    }

    public function test_the_last_active_company_cannot_be_archived(): void
    {
        $this->postJson("/api/v1/companies/{$this->acme->id}/archive", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_active');
    }

    public function test_a_company_with_active_branches_cannot_be_archived(): void
    {
        $this->inTenant(fn () => $this->company('Spare'));

        $this->postJson("/api/v1/companies/{$this->acme->id}/archive", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'has_active_children');
    }

    public function test_a_branch_manager_cannot_create_a_company_nor_list_companies(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->postJson('/api/v1/companies', ['name' => 'Mine', 'country' => 'KE'], $this->headersFor($manager))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        // RBAC-04: a branch role does not reach the company record itself.
        $this->getJson('/api/v1/companies', $this->headersFor($manager))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/companies/{$this->acme->id}", $this->headersFor($manager))->assertNotFound();
    }

    public function test_a_company_admin_cannot_edit_without_the_permission(): void
    {
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));

        $this->getJson("/api/v1/companies/{$this->acme->id}", $this->headersFor($accountant))->assertOk();
        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['name' => 'X'], $this->headersFor($accountant))
            ->assertForbidden();
        $this->postJson("/api/v1/companies/{$this->acme->id}/archive", [], $this->headersFor($accountant))
            ->assertForbidden();
    }

    public function test_another_tenants_company_is_not_found(): void
    {
        $other = $this->otherTenant();

        $this->getJson("/api/v1/companies/{$other['company']->id}", $this->headersFor())->assertNotFound();
        $this->patchJson("/api/v1/companies/{$other['company']->id}", ['name' => 'Mine'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/companies/{$other['company']->id}/archive", [], $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/companies/not-a-uuid', $this->headersFor())->assertNotFound();

        $this->getJson('/api/v1/companies?status=all', $this->headersFor())
            ->assertJsonMissing(['id' => $other['company']->id]);
    }

    public function test_pagination_is_capped(): void
    {
        $this->getJson('/api/v1/companies?per_page=500', $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/companies?per_page=1', $this->headersFor())
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }
}
