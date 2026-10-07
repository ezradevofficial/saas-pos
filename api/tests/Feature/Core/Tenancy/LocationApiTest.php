<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-05 locations, TEN-06 archive/restore, RBAC-04 scoped visibility.
class LocationApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_the_owner_creates_updates_archives_and_restores_a_location(): void
    {
        $id = $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", [
            'name' => 'Back store',
            'type' => 'warehouse',
        ], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branchA->id)
            ->assertJsonPath('data.type', 'warehouse')
            ->json('data.id');

        $this->patchJson("/api/v1/locations/{$id}", ['name' => 'Main store', 'type' => 'store'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.name', 'Main store')
            ->assertJsonPath('data.type', 'store');

        $this->postJson("/api/v1/locations/{$id}/archive", [], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/branches/{$this->branchA->id}/locations", $this->headersFor())->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/branches/{$this->branchA->id}/locations?status=all", $this->headersFor())->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/locations/{$id}/restore", [], $this->headersFor())->assertOk();
    }

    public function test_create_validates_the_type(): void
    {
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'X', 'type' => 'garage'], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_archiving_the_only_active_location_is_refused(): void
    {
        $this->inTenant(fn () => $this->locationB->archive());

        $this->postJson("/api/v1/locations/{$this->locationA->id}/archive", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_active');
    }

    public function test_a_branch_manager_of_branch_a_cannot_see_branch_b_locations(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->getJson("/api/v1/branches/{$this->branchA->id}/locations", $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->locationA->id);
        $this->getJson("/api/v1/branches/{$this->branchB->id}/locations", $this->headersFor($manager))->assertNotFound();
        $this->getJson("/api/v1/locations/{$this->locationB->id}", $this->headersFor($manager))->assertNotFound();
        $this->postJson("/api/v1/branches/{$this->branchB->id}/locations", ['name' => 'X', 'type' => 'outlet'], $this->headersFor($manager))
            ->assertNotFound();
        $this->getJson('/api/v1/locations', $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // core.location.* at their own branch.
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Kiosk', 'type' => 'outlet'], $this->headersFor($manager))
            ->assertCreated();
    }

    public function test_a_cashier_at_a_location_cannot_create_locations(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->getJson("/api/v1/locations/{$this->locationA->id}", $this->headersFor($cashier))->assertOk();
        $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'X', 'type' => 'outlet'], $this->headersFor($cashier))
            ->assertForbidden();
        $this->patchJson("/api/v1/locations/{$this->locationA->id}", ['name' => 'X'], $this->headersFor($cashier))
            ->assertForbidden();
    }

    public function test_another_tenants_locations_are_not_found(): void
    {
        $other = $this->otherTenant();

        $this->getJson("/api/v1/locations/{$other['location']->id}", $this->headersFor())->assertNotFound();
        $this->getJson("/api/v1/branches/{$other['branch']->id}/locations", $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/locations/{$other['location']->id}/archive", [], $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/locations?status=all', $this->headersFor())->assertJsonCount(2, 'data');
    }
}
