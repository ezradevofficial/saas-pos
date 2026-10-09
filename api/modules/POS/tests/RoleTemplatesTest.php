<?php

namespace Modules\POS\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\POS\PosServiceProvider;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08: what the system roles may do at the till once the POS
// module is active, and nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private const ALL = [
        'pos.cash.move', 'pos.discount.give', 'pos.layout.edit', 'pos.layout.publish', 'pos.layout.view', 'pos.price.override',
        'pos.sale.create', 'pos.sale.print', 'pos.sale.refund', 'pos.sale.review', 'pos.sale.share', 'pos.sale.view', 'pos.sale.void',
        'pos.shift.close', 'pos.shift.manage', 'pos.shift.open', 'pos.shift.view', 'pos.till.sign_in',
    ];

    /** @return list<string> the pos.* permissions $template holds at location A */
    private function posPermissions(string $template): array
    {
        $user = $this->userWith($template, Scope::location($this->locationA->id));

        return $this->inTenant(fn () => collect(self::ALL)
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::location($this->locationA->id)))
            ->values()->all());
    }

    public function test_the_catalogue_has_every_pos_permission(): void
    {
        $this->assertSame(self::ALL, collect(PosServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "pos.{$resource}.{$action}", $actions))
            ->sort()->values()->all());
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $this->setUpPos();

        $this->assertSame(['pos.sale.create', 'pos.sale.print', 'pos.sale.view', 'pos.shift.close', 'pos.shift.open', 'pos.shift.view', 'pos.till.sign_in'], $this->posPermissions('cashier'));
        $this->assertSame(self::ALL, $this->posPermissions('branch_manager'));
        // LAY-05: the Admin designs the till's layout; readers of every POS page see it too.
        $this->assertSame(['pos.layout.edit', 'pos.layout.publish', 'pos.layout.view', 'pos.sale.share'], $this->posPermissions('admin'));
        $this->assertSame(['pos.layout.view', 'pos.sale.view', 'pos.shift.view'], $this->posPermissions('accountant'));
        $this->assertSame(['pos.layout.view', 'pos.sale.view', 'pos.shift.view'], $this->posPermissions('read_only_auditor'));
        $this->assertSame(['pos.sale.print', 'pos.sale.view'], $this->posPermissions('waiter'));
        $this->assertSame([], $this->posPermissions('hr_officer'));

        // RBAC-08: with the module off, the same roles grant nothing of it.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->assertSame([], $this->posPermissions('branch_manager'));
    }
}
