<?php

namespace Modules\Inventory\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08: what the system roles may do with stock once the
// Inventory module is active, and nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    private const ALL = [
        'inventory.adjustment.approve', 'inventory.adjustment.cancel', 'inventory.adjustment.create', 'inventory.adjustment.view',
        'inventory.count.approve', 'inventory.count.cancel', 'inventory.count.count', 'inventory.count.create', 'inventory.count.view',
        'inventory.item.configure', 'inventory.label.print', 'inventory.opening.create',
        'inventory.reorder.edit', 'inventory.reorder.view', 'inventory.settings.edit', 'inventory.stock.view',
        'inventory.transfer.approve', 'inventory.transfer.cancel', 'inventory.transfer.create', 'inventory.transfer.dispatch',
        'inventory.transfer.receive', 'inventory.transfer.view', 'inventory.valuation.view',
    ];

    private const VIEWS = [
        'inventory.adjustment.view', 'inventory.count.view', 'inventory.reorder.view', 'inventory.stock.view',
        'inventory.transfer.view', 'inventory.valuation.view',
    ];

    /** @return list<string> the inventory.* permissions $template holds at location A */
    private function held(string $template): array
    {
        $user = $this->userWith($template, Scope::location($this->locationA->id));

        return $this->inTenant(fn () => collect(self::ALL)
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::location($this->locationA->id)))
            ->values()->all());
    }

    public function test_the_catalogue_has_every_inventory_permission(): void
    {
        $this->assertSame(self::ALL, collect(InventoryServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "inventory.{$resource}.{$action}", $actions))
            ->sort()->values()->all());
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $this->setUpInventory();

        $this->assertSame(self::ALL, $this->held('branch_manager'));
        $this->assertSame(array_values(array_diff(self::ALL, [
            'inventory.adjustment.approve', 'inventory.count.approve', 'inventory.transfer.approve', 'inventory.settings.edit', 'inventory.valuation.view',
        ])), $this->held('storekeeper'));
        $this->assertSame(collect(['inventory.item.configure', 'inventory.label.print', 'inventory.reorder.edit', 'inventory.settings.edit', ...self::VIEWS])->sort()->values()->all(), $this->held('admin'));
        $this->assertSame(self::VIEWS, $this->held('accountant'));
        $this->assertSame(self::VIEWS, $this->held('read_only_auditor'));
        // RBAC-05: buyers see stock, never its value.
        $this->assertSame(array_values(array_diff(self::VIEWS, ['inventory.valuation.view'])), $this->held('procurement_officer'));
        // Approvers read what they approve, never stock values.
        $this->assertSame(['inventory.adjustment.approve', 'inventory.adjustment.view', 'inventory.count.approve', 'inventory.count.view', 'inventory.transfer.approve', 'inventory.transfer.view'], $this->held('approver'));
        $this->assertSame([], $this->held('cashier'));
        // ACC-04: approvers read the journals they approve (once Accounting is active).
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('accounting'));
        $approver = $this->userWith('approver', Scope::location($this->locationA->id));
        $this->assertTrue($this->inTenant(fn () => app(ScopeResolver::class)->can($approver, 'accounting.journal.view', Scope::location($this->locationA->id))));

        // RBAC-08: with the module off, the same roles grant nothing of it.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('inventory'));
        $this->assertSame([], $this->held('branch_manager'));
    }
}
