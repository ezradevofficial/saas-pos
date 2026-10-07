<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-04 branches, TEN-06 archive/restore, RBAC-04 scoped visibility.
class BranchApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_the_owner_creates_updates_archives_and_restores_a_branch(): void
    {
        $id = $this->postJson("/api/v1/companies/{$this->acme->id}/branches", [
            'name' => 'Mombasa',
            'code' => 'msa',
            'timezone' => 'Africa/Nairobi',
        ], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->acme->id)
            ->assertJsonPath('data.code', 'MSA')
            ->json('data.id');

        $this->patchJson("/api/v1/branches/{$id}", ['name' => 'Mombasa Road'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.name', 'Mombasa Road');

        $this->postJson("/api/v1/branches/{$id}/archive", [], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/companies/{$this->acme->id}/branches", $this->headersFor())->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/companies/{$this->acme->id}/branches?status=all", $this->headersFor())->assertJsonCount(3, 'data');

        $this->postJson("/api/v1/branches/{$id}/restore", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    }

    public function test_branch_codes_are_unique_per_company_among_active_branches(): void
    {
        $this->postJson("/api/v1/companies/{$this->acme->id}/branches", ['name' => 'Dup', 'code' => 'A'], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $c = $this->postJson("/api/v1/companies/{$this->acme->id}/branches", ['name' => 'C', 'code' => 'C'], $this->headersFor())
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/branches/{$c}/archive", [], $this->headersFor())->assertOk();

        // An archived branch frees its code; restoring it while taken is refused.
        $this->postJson("/api/v1/companies/{$this->acme->id}/branches", ['name' => 'C2', 'code' => 'C'], $this->headersFor())
            ->assertCreated();
        $this->postJson("/api/v1/branches/{$c}/restore", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_a_branch_with_active_locations_cannot_be_archived(): void
    {
        $this->postJson("/api/v1/branches/{$this->branchA->id}/archive", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'has_active_children');
    }

    public function test_the_last_active_branch_cannot_be_archived(): void
    {
        $this->inTenant(function () {
            $this->locationB->archive();
            $this->branchB->archive();
            $this->locationA->archive();
        });

        $this->postJson("/api/v1/branches/{$this->branchA->id}/archive", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_active');
    }

    public function test_a_branch_manager_sees_only_their_branch_and_cannot_create_one(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->getJson("/api/v1/companies/{$this->acme->id}/branches", $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->branchA->id);
        $this->getJson('/api/v1/branches', $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/branches/{$this->branchA->id}", $this->headersFor($manager))->assertOk();
        $this->getJson("/api/v1/branches/{$this->branchB->id}", $this->headersFor($manager))->assertNotFound();
        $this->patchJson("/api/v1/branches/{$this->branchB->id}", ['name' => 'X'], $this->headersFor($manager))->assertNotFound();

        // RBAC-04: edit at the own branch, but branches are created at company scope.
        $this->patchJson("/api/v1/branches/{$this->branchA->id}", ['name' => 'Branch A1'], $this->headersFor($manager))->assertOk();
        $this->postJson("/api/v1/companies/{$this->acme->id}/branches", ['name' => 'New', 'code' => 'N'], $this->headersFor($manager))
            ->assertForbidden();
        $this->postJson("/api/v1/branches/{$this->branchA->id}/archive", [], $this->headersFor($manager))->assertForbidden();
    }

    public function test_another_tenants_branches_are_not_found(): void
    {
        $other = $this->otherTenant();

        $this->getJson("/api/v1/branches/{$other['branch']->id}", $this->headersFor())->assertNotFound();
        $this->getJson("/api/v1/companies/{$other['company']->id}/branches", $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/companies/{$other['company']->id}/branches", ['name' => 'X', 'code' => 'X'], $this->headersFor())
            ->assertNotFound();
        $this->postJson("/api/v1/branches/{$other['branch']->id}/archive", [], $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/branches?status=all', $this->headersFor())->assertJsonCount(2, 'data');
    }
}
