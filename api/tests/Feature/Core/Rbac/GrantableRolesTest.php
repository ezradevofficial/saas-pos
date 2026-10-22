<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-04, RBAC-10 (security review 5): role pickers ask the API which roles
// may be granted at a place, by the same rules as the grant itself.
class GrantableRolesTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    /** @return list<string> template keys of the roles offered */
    private function grantable(string $at, ?array $headers = null, string $for = 'assign'): array
    {
        $ids = collect($this->getJson('/api/v1/roles?per_page=200&grantable_at='.$at.'&grantable_for='.$for, $headers ?? $this->headersFor())->assertOk()->json('data'))->pluck('id')->all();

        return $this->roles->filter(fn ($role) => in_array($role->id, $ids, true))->keys()->sort()->values()->all();
    }

    public function test_an_owner_with_only_core_active_can_pick_admin(): void
    {
        // Admin holds module permissions; no module is active, yet the Owner's roles hold them all.
        $this->getJson('/api/v1/me/permissions', $this->headersFor())->assertOk()->assertJsonPath('modules', ['core']);

        $this->assertContains('admin', $this->grantable('tenant'));
        $this->assertContains('admin', $this->grantable('branch:'.$this->branchA->id));
        $this->assertContains('owner', $this->grantable('any'), 'an Owner may grant Owner');
    }

    public function test_a_branch_manager_is_offered_only_what_they_may_grant_at_their_branch(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $atA = $this->grantable('branch:'.$this->branchA->id, $this->headersFor($manager));
        $this->assertNotContains('owner', $atA);
        $this->assertNotContains('admin', $atA);

        $this->assertNotSame([], $atA);
        $colleague = $this->userWith('cashier', Scope::location($this->locationA->id));

        foreach ($atA as $key) {
            $this->postJson("/api/v1/users/{$colleague->id}/assignments", ['role_id' => $this->roles->get($key)->id, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id], $this->headersFor($manager))
                ->assertSuccessful();
        }

        // A place out of their reach is not confirmed (as a grant there would be refused), and none at tenant level.
        $this->getJson('/api/v1/roles?grantable_at=branch:'.$this->branchB->id, $this->headersFor($manager))->assertNotFound();
        $this->assertSame([], $this->grantable('tenant', $this->headersFor($manager)));
    }

    public function test_an_unknown_or_other_tenants_place_is_not_found_and_bad_values_are_refused(): void
    {
        $other = $this->otherTenant();

        $this->getJson('/api/v1/roles?grantable_at=branch:'.$other['branch']->id, $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/roles?grantable_at=everywhere', $this->headersFor())->assertUnprocessable();
        $this->getJson('/api/v1/roles?grantable_at=tenant&grantable_for=delete', $this->headersFor())->assertUnprocessable();
    }
}
