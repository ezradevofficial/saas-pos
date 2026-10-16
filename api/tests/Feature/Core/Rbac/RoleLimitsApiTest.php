<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-06 limit rules edited per role (owner decision A, 2026-10-16), RBAC-02 and RBAC-03
// (system roles read-only, their copies editable), RBAC-12 (audited), no privilege escalation.
class RoleLimitsApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function customRole(string $name = 'Supervisor'): string
    {
        return $this->postJson('/api/v1/roles', ['name' => $name, 'permissions' => ['core.location.view']], $this->headersFor())
            ->assertCreated()->json('data.id');
    }

    private function putLimits(string $roleId, array $limits, $as = null)
    {
        return $this->putJson("/api/v1/roles/{$roleId}/limits", ['limits' => $limits], $this->headersFor($as));
    }

    public function test_the_owner_sets_and_clears_a_custom_roles_limits_and_each_change_is_audited(): void
    {
        $id = $this->customRole();

        $this->getJson("/api/v1/roles/{$id}", $this->headersFor())->assertOk()->assertJsonPath('data.limits', []);

        $this->putLimits($id, [
            'max_discount_percent' => '12.5',
            'max_quote_discount_percent' => '5',
            'max_refund_amount' => '20000',
        ])->assertOk()
            ->assertJsonPath('data.limits', ['max_discount_percent' => '12.5', 'max_quote_discount_percent' => '5', 'max_refund_amount' => '20000']);

        // Keys left out stay; null clears one.
        $this->putLimits($id, ['max_refund_amount' => null, 'max_approval_amount' => '150000.50'])
            ->assertOk()
            ->assertJsonPath('data.limits', ['max_approval_amount' => '150000.5', 'max_discount_percent' => '12.5', 'max_quote_discount_percent' => '5']);

        $this->inTenant(function () use ($id) {
            $this->assertSame(3, LimitRule::where('role_id', $id)->count());
            $entries = AuditEntry::where('action', 'rbac.role.limits_update')->where('auditable_id', $id)->orderBy('seq')->get();
            $this->assertCount(2, $entries);
            $this->assertSame(['limits' => []], $entries[0]->before);
            $this->assertEquals(['limits' => ['max_discount_percent' => '12.5', 'max_quote_discount_percent' => '5', 'max_refund_amount' => '20000']], $entries[0]->after);
            $this->assertEquals(['limits' => ['max_approval_amount' => '150000.5', 'max_discount_percent' => '12.5', 'max_quote_discount_percent' => '5']], $entries[1]->after);
        });

        // Nothing changes, nothing is audited.
        $this->putLimits($id, ['max_discount_percent' => '12.50'])->assertOk();
        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'rbac.role.limits_update')->where('auditable_id', $id)->count()));
    }

    public function test_system_roles_show_their_limits_but_only_their_copies_are_edited(): void
    {
        $manager = $this->roles->get('branch_manager');

        $this->getJson("/api/v1/roles/{$manager->id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.limits', ['max_quote_discount_percent' => '10']);
        $this->putLimits($manager->id, ['max_quote_discount_percent' => '20'])->assertForbidden()->assertJsonPath('code', 'system_role');

        $copy = $this->postJson("/api/v1/roles/{$manager->id}/copy", ['name' => 'Senior manager'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.limits', ['max_quote_discount_percent' => '10'])->json('data.id');

        $this->putLimits($copy, ['max_quote_discount_percent' => '20'])->assertOk()->assertJsonPath('data.limits.max_quote_discount_percent', '20');
        $this->inTenant(fn () => $this->assertSame('10.0000', LimitRule::where('role_id', $manager->id)->sole()->value));
    }

    public function test_values_are_validated(): void
    {
        $id = $this->customRole();

        $this->putLimits($id, ['max_discount_percent' => '100.01', 'max_quote_discount_percent' => '-1', 'max_refund_amount' => 'ten', 'nope' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limits.max_discount_percent', 'limits.max_quote_discount_percent', 'limits.max_refund_amount', 'limits']);
        $this->putLimits($id, ['max_refund_amount' => '1.23456'])->assertUnprocessable()->assertJsonValidationErrors(['limits.max_refund_amount']);
        $this->putJson("/api/v1/roles/{$id}/limits", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors(['limits']);
    }

    public function test_an_admin_cannot_raise_a_limit_above_their_own_but_may_lower_it(): void
    {
        $id = $this->customRole();
        $this->putLimits($id, ['max_refund_amount' => '50000'])->assertOk();
        $admin = $this->userWith('admin', Scope::tenant());

        $this->putLimits($id, ['max_refund_amount' => '60000'], $admin)->assertForbidden()->assertJsonPath('code', 'cannot_grant_limit');
        $this->putLimits($id, ['max_discount_percent' => '1'], $admin)->assertForbidden()->assertJsonPath('code', 'cannot_grant_limit');
        $this->putLimits($id, ['max_refund_amount' => '1000'], $admin)->assertOk()->assertJsonPath('data.limits.max_refund_amount', '1000');
        $this->putLimits($id, ['max_refund_amount' => null], $admin)->assertOk()->assertJsonPath('data.limits', []);

        // An admin holding a limit at tenant scope grants up to it.
        $this->inTenant(function () use ($admin) {
            $role = Role::create(['name' => 'Discount desk', 'guard_name' => 'web']);
            $role->limitRules()->create(['key' => 'max_discount_percent', 'value' => '15']);
            $this->assign($admin, $role, Scope::tenant());
        });
        $this->putLimits($id, ['max_discount_percent' => '15'], $admin)->assertOk();
        $this->putLimits($id, ['max_discount_percent' => '15.01'], $admin)->assertForbidden();
    }

    public function test_only_role_editors_change_limits_and_never_another_tenants_role(): void
    {
        $id = $this->customRole();
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->putLimits($id, ['max_discount_percent' => '0'], $manager)->assertForbidden();

        $other = $this->otherTenant()['user'];
        $this->putLimits($id, ['max_discount_percent' => '0'], $other)->assertNotFound();
    }
}
