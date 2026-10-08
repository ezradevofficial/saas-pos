<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-05: departments, cost centres and projects per company: codes unique
// (case-insensitive) among the company's active rows, a tree within the
// company, an owner who can view the company (APR-02).
class DimensionApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const KINDS = ['departments' => 'department', 'cost-centres' => 'cost_centre', 'projects' => 'project'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function create(string $kind, array $body, ?array $headers = null, ?string $companyId = null)
    {
        return $this->postJson('/api/v1/companies/'.($companyId ?? $this->acme->id)."/{$kind}", $body, $headers ?? $this->headersFor());
    }

    public function test_each_kind_is_created_listed_changed_archived_and_restored(): void
    {
        foreach (self::KINDS as $kind => $type) {
            $id = $this->create($kind, ['code' => ' ADM ', 'name' => 'Administration', 'owner_user_id' => $this->owner->id])
                ->assertCreated()->assertJsonPath('data.code', 'ADM')->assertJsonPath('data.owner_user_id', $this->owner->id)->json('data.id');

            $this->getJson("/api/v1/companies/{$this->acme->id}/{$kind}", $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
            $this->getJson("/api/v1/{$kind}/{$id}", $this->headersFor())->assertOk()->assertJsonPath('data.name', 'Administration');
            $this->patchJson("/api/v1/{$kind}/{$id}", ['name' => 'Admin', 'owner_user_id' => null], $this->headersFor())
                ->assertOk()->assertJsonPath('data.name', 'Admin')->assertJsonPath('data.owner_user_id', null);
            $this->postJson("/api/v1/{$kind}/{$id}/archive", [], $this->headersFor())->assertOk();
            $this->getJson("/api/v1/companies/{$this->acme->id}/{$kind}", $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
            $this->getJson("/api/v1/companies/{$this->acme->id}/{$kind}?status=archived", $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
            $this->postJson("/api/v1/{$kind}/{$id}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);

            $this->getJson("/api/v1/history/{$type}/{$id}", $this->headersFor())->assertOk()
                ->assertJsonPath('data.0.action', "core.{$type}.restore")
                ->assertJsonPath('data.3.action', "core.{$type}.create");
        }

        // Each kind is its own table: a department id is not a cost centre.
        $department = $this->create('departments', ['code' => 'OPS', 'name' => 'Operations'])->json('data.id');
        $this->getJson("/api/v1/cost-centres/{$department}", $this->headersFor())->assertNotFound();
    }

    public function test_codes_are_unique_per_company_among_active_rows_whatever_the_case(): void
    {
        $id = $this->create('cost-centres', ['code' => 'CC-100', 'name' => 'Head office'])->json('data.id');

        $this->create('cost-centres', ['code' => 'cc-100', 'name' => 'Copy'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->create('cost-centres', ['code' => 'has space', 'name' => 'Bad'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->create('cost-centres', ['name' => 'No code'])->assertUnprocessable()->assertJsonValidationErrors('code');
        // Another kind, or another company, may use it.
        $this->create('projects', ['code' => 'CC-100', 'name' => 'Project'])->assertCreated();
        $globex = $this->inTenant(fn () => $this->company('Globex'));
        $this->create('cost-centres', ['code' => 'CC-100', 'name' => 'Globex HQ'], null, $globex->id)->assertCreated();

        // Archiving frees the code; restoring while it is taken is refused.
        $this->postJson("/api/v1/cost-centres/{$id}/archive", [], $this->headersFor())->assertOk();
        $this->create('cost-centres', ['code' => 'Cc-100', 'name' => 'New head office'])->assertCreated();
        $this->postJson("/api/v1/cost-centres/{$id}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->inTenant(fn () => $this->assertNotNull(CostCentre::findOrFail($id)->archived_at));
    }

    public function test_the_tree_stays_within_the_company_and_has_no_cycles(): void
    {
        $root = $this->create('departments', ['code' => 'ROOT', 'name' => 'Root'])->json('data.id');
        $child = $this->create('departments', ['code' => 'CHILD', 'name' => 'Child', 'parent_id' => $root])->assertCreated()->assertJsonPath('data.parent_id', $root)->json('data.id');
        $grandchild = $this->create('departments', ['code' => 'GRAND', 'name' => 'Grandchild', 'parent_id' => $child])->json('data.id');

        $this->patchJson("/api/v1/departments/{$root}", ['parent_id' => $grandchild], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/departments/{$root}", ['parent_id' => $root], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // A parent of another company, or another kind, is refused.
        $globex = $this->inTenant(fn () => $this->company('Globex'));
        $foreign = $this->create('departments', ['code' => 'G', 'name' => 'Globex'], null, $globex->id)->json('data.id');
        $this->create('departments', ['code' => 'X', 'name' => 'X', 'parent_id' => $foreign])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $project = $this->create('projects', ['code' => 'P', 'name' => 'P'])->json('data.id');
        $this->create('departments', ['code' => 'Y', 'name' => 'Y', 'parent_id' => $project])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // A row with active children stays; a child comes back after its parent.
        $this->postJson("/api/v1/departments/{$child}/archive", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'dimension_in_use');
        $this->postJson("/api/v1/departments/{$grandchild}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/departments/{$child}/archive", [], $this->headersFor())->assertOk();
        $this->create('departments', ['code' => 'Z', 'name' => 'Z', 'parent_id' => $child])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson("/api/v1/departments/{$grandchild}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'parent_archived');
        $this->postJson("/api/v1/departments/{$child}/restore", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/departments/{$grandchild}/restore", [], $this->headersFor())->assertOk();

        // Moving to the top is fine.
        $this->patchJson("/api/v1/departments/{$grandchild}", ['parent_id' => null], $this->headersFor())->assertOk()->assertJsonPath('data.parent_id', null);
    }

    public function test_the_owner_can_view_the_company(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $former = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->inTenant(fn () => $former->update(['status' => 'deactivated']));
        $other = $this->otherTenant();

        $this->create('cost-centres', ['code' => 'A', 'name' => 'A', 'owner_user_id' => $manager->id])->assertCreated();
        $this->create('cost-centres', ['code' => 'B', 'name' => 'B', 'owner_user_id' => $cashier->id])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->create('cost-centres', ['code' => 'C', 'name' => 'C', 'owner_user_id' => $former->id])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->create('cost-centres', ['code' => 'D', 'name' => 'D', 'owner_user_id' => $other['user']->id])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
    }

    public function test_scope_and_permissions(): void
    {
        $id = $this->create('departments', ['code' => 'FIN', 'name' => 'Finance'])->json('data.id');

        foreach (['hr_officer', 'procurement_officer'] as $template) {
            $reader = $this->headersFor($this->userWith($template, Scope::company($this->acme->id)));
            $this->getJson("/api/v1/companies/{$this->acme->id}/departments", $reader)->assertOk()->assertJsonCount(1, 'data');
            $this->getJson("/api/v1/departments/{$id}", $reader)->assertOk();
            $this->create('departments', ['code' => 'NEW', 'name' => 'New'], $reader)->assertForbidden();
            $this->patchJson("/api/v1/departments/{$id}", ['name' => 'Mine'], $reader)->assertForbidden();
            $this->postJson("/api/v1/departments/{$id}/archive", [], $reader)->assertForbidden();
        }

        $accountant = $this->headersFor($this->userWith('accountant', Scope::company($this->acme->id)));
        $this->create('projects', ['code' => 'P1', 'name' => 'Launch'], $accountant)->assertCreated();
        $this->patchJson("/api/v1/departments/{$id}", ['name' => 'Finance and admin'], $accountant)->assertOk();

        // Sees the company but holds no dimension permission: forbidden; cannot see it: not found.
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->getJson("/api/v1/departments/{$id}", $manager)->assertForbidden();
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/departments/{$id}", $cashier)->assertNotFound();

        $other = $this->otherTenant();
        $this->getJson("/api/v1/departments/{$id}", $this->bearer($this->tokenFor($other['user'])))->assertNotFound();
        $this->getJson("/api/v1/history/department/{$id}", $this->bearer($this->tokenFor($other['user'])))->assertNotFound();
    }
}
