<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-10, review focus 5: the last active Owner cannot be removed or demoted.
class OwnerSafetyTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function ownerAssignmentOf(User $user): string
    {
        return $this->inTenant(fn () => RoleAssignment::where('user_id', $user->id)
            ->where('role_id', $this->roles->get('owner')->id)->value('id'));
    }

    public function test_the_last_owner_assignment_cannot_be_removed(): void
    {
        $this->deleteJson("/api/v1/assignments/{$this->ownerAssignmentOf($this->owner)}", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_owner');

        $this->inTenant(fn () => $this->assertTrue(app(OwnerGuard::class)->isOwner($this->owner)));
    }

    public function test_the_last_owner_cannot_be_deactivated(): void
    {
        $this->postJson("/api/v1/users/{$this->owner->id}/deactivate", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_owner');

        $this->inTenant(fn () => $this->assertSame('active', $this->owner->fresh()->status));
    }

    public function test_with_two_owners_one_can_be_removed_but_not_both(): void
    {
        $second = $this->userWith('owner', Scope::tenant());

        $this->deleteJson("/api/v1/assignments/{$this->ownerAssignmentOf($second)}", [], $this->headersFor())->assertNoContent();
        $this->deleteJson("/api/v1/assignments/{$this->ownerAssignmentOf($this->owner)}", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_owner');
    }

    public function test_with_two_owners_one_can_be_deactivated_but_not_both(): void
    {
        $second = $this->userWith('owner', Scope::tenant());

        $this->postJson("/api/v1/users/{$second->id}/deactivate", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/users/{$this->owner->id}/deactivate", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_owner');
    }

    public function test_an_owner_assignment_below_tenant_scope_does_not_count(): void
    {
        $second = $this->userWith('owner', Scope::company($this->acme->id));

        $this->postJson("/api/v1/users/{$this->owner->id}/deactivate", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_owner');
        $this->deleteJson("/api/v1/assignments/{$this->ownerAssignmentOf($second)}", [], $this->headersFor())->assertNoContent();
    }

    public function test_the_guard_locks_the_owner_set_so_two_removals_cannot_both_pass(): void
    {
        $second = $this->userWith('owner', Scope::tenant());
        $guard = app(OwnerGuard::class);

        $this->inTenant(function () use ($guard, $second) {
            DB::transaction(function () use ($guard, $second) {
                // Each flow checks, then changes, under the owners lock.
                $guard->protect($second, fn () => $second->forceFill(['status' => 'deactivated'])->save());

                $locks = DB::selectOne("select count(*) as n from pg_locks where locktype = 'advisory' and pid = pg_backend_pid() and granted");
                $this->assertGreaterThan(0, $locks->n);

                try {
                    $guard->protect($this->owner, fn () => $this->owner->forceFill(['status' => 'deactivated'])->save());
                    $this->fail('The second removal passed.');
                } catch (ApiException $e) {
                    $this->assertSame('last_owner', $e->errorCode);
                }
            });

            $this->assertSame('active', $this->owner->fresh()->status);
        });
    }

    public function test_only_an_owner_grants_or_removes_the_owner_role(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $second = $this->userWith('owner', Scope::tenant());
        $user = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $this->roles->get('owner')->id, 'scope_type' => 'tenant', 'scope_id' => $this->owner->tenant_id,
        ], $this->headersFor($admin))->assertForbidden()->assertJsonPath('code', 'cannot_grant');

        $this->deleteJson("/api/v1/assignments/{$this->ownerAssignmentOf($second)}", [], $this->headersFor($admin))
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_grant');
    }
}
