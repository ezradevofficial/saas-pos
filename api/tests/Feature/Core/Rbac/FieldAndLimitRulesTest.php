<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use InvalidArgumentException;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-05 field rules, RBAC-06 limit rules.
class FieldAndLimitRulesTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    private User $user;

    private Role $clerk;

    private Role $supervisor;

    private Branch $branchA;

    private Branch $branchB;

    private Location $locationA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        app(TenantContext::class)->set($this->user->tenant_id);

        $company = $this->company();
        $this->branchA = $this->branch($company, 'A');
        $this->branchB = $this->branch($company, 'B');
        $this->locationA = $this->location($this->branchA);

        $this->clerk = $this->role('Clerk', ['core.location.view']);
        $this->supervisor = $this->role('Supervisor', ['core.location.view']);
    }

    private function fieldRule(Role $role, string $field, string $mode): void
    {
        FieldRule::create(['role_id' => $role->id, 'resource' => 'product', 'field' => $field, 'mode' => $mode]);
    }

    public function test_a_field_is_hidden_only_when_every_role_hides_it(): void
    {
        $this->assign($this->user, $this->clerk, Scope::branch($this->branchA->id));
        $this->assign($this->user, $this->supervisor, Scope::branch($this->branchB->id));

        $this->fieldRule($this->clerk, 'cost_price', 'hidden');
        $this->fieldRule($this->clerk, 'margin', 'hidden');
        $this->fieldRule($this->clerk, 'supplier', 'readonly');
        $this->fieldRule($this->supervisor, 'margin', 'readonly');

        $rules = app(FieldRules::class)->for($this->user, 'product');
        $this->assertSame([], $rules['hidden']);
        // Hidden by one role and read-only for the other: visible, not editable.
        $this->assertSame(['margin'], $rules['readonly']);

        $this->fieldRule($this->supervisor, 'cost_price', 'hidden');

        $rules = app(FieldRules::class)->for($this->user, 'product');
        $this->assertSame(['cost_price'], $rules['hidden']);
        $this->assertSame(['margin'], $rules['readonly']);
        $this->assertSame(['hidden' => [], 'readonly' => []], app(FieldRules::class)->for($this->user, 'customer'));

        $this->assertSame(
            ['name' => 'Soap', 'margin' => '0.2', 'supplier' => 'Acme'],
            app(FieldRules::class)->filter($this->user, 'product', ['name' => 'Soap', 'cost_price' => '10', 'margin' => '0.2', 'supplier' => 'Acme']),
        );
    }

    public function test_a_user_without_roles_has_no_field_rules(): void
    {
        $this->fieldRule($this->clerk, 'cost_price', 'hidden');

        $this->assertSame(['hidden' => [], 'readonly' => []], app(FieldRules::class)->for($this->user, 'product'));
    }

    public function test_an_archived_role_does_not_count(): void
    {
        $this->assign($this->user, $this->clerk, Scope::tenant());
        $this->assign($this->user, $this->supervisor, Scope::tenant());
        $this->fieldRule($this->clerk, 'cost_price', 'hidden');

        $this->supervisor->archive();

        $this->assertSame(['cost_price'], app(FieldRules::class)->for($this->user, 'product')['hidden']);
    }

    public function test_the_highest_limit_across_covering_roles_applies(): void
    {
        $this->assign($this->user, $this->clerk, Scope::branch($this->branchA->id));
        $this->assign($this->user, $this->supervisor, Scope::branch($this->branchB->id));

        LimitRule::create(['role_id' => $this->clerk->id, 'key' => 'max_discount_percent', 'value' => '10']);
        LimitRule::create(['role_id' => $this->supervisor->id, 'key' => 'max_discount_percent', 'value' => '25.5']);

        $limits = app(LimitRules::class);
        $this->assertSame('25.5000', $limits->max($this->user, 'max_discount_percent', null));
        // Only the clerk role covers branch A's location.
        $this->assertSame('10.0000', $limits->max($this->user, 'max_discount_percent', Scope::location($this->locationA->id)));
        $this->assertSame('25.5000', $limits->max($this->user, 'max_discount_percent', Scope::branch($this->branchB->id)));

        // No rule configured: not allowed.
        $this->assertNull($limits->max($this->user, 'max_refund_amount', null));
    }

    public function test_the_same_role_at_two_scopes_counts_once_and_an_unknown_key_fails(): void
    {
        $this->assign($this->user, $this->clerk, Scope::branch($this->branchA->id));
        $this->assign($this->user, $this->supervisor, Scope::branch($this->branchA->id));
        LimitRule::create(['role_id' => $this->clerk->id, 'key' => 'max_approval_amount', 'value' => '1000']);
        LimitRule::create(['role_id' => $this->supervisor->id, 'key' => 'max_approval_amount', 'value' => '50000']);

        $this->assertSame('50000.0000', app(LimitRules::class)->max($this->user, 'max_approval_amount', Scope::location($this->locationA->id)));

        $this->expectException(InvalidArgumentException::class);
        app(LimitRules::class)->max($this->user, 'max_everything', null);
    }

    public function test_a_deactivated_user_gets_no_field_or_limit_rules(): void
    {
        $this->assign($this->user, $this->clerk, Scope::tenant());
        $this->fieldRule($this->clerk, 'cost_price', 'hidden');
        LimitRule::create(['role_id' => $this->clerk->id, 'key' => 'max_discount_percent', 'value' => '10']);

        $this->user->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->assertSame([], app(ScopeResolver::class)->roleIds($this->user));
        $this->assertSame(['hidden' => [], 'readonly' => []], app(FieldRules::class)->for($this->user, 'product'));
        $this->assertNull(app(LimitRules::class)->max($this->user, 'max_discount_percent', null));
    }
}
