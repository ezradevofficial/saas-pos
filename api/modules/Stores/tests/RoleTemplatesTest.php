<?php

namespace Modules\Stores\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Stores\StoresServiceProvider;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08, STR-13: what the system roles may do in Stores once
// the module is active, and nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    private const ALL = [
        'stores.issue.acknowledge', 'stores.issue.create', 'stores.issue.view', 'stores.ledger.view', 'stores.report.view',
        'stores.requisition.approve', 'stores.requisition.cancel', 'stores.requisition.create', 'stores.requisition.view',
        'stores.return.create', 'stores.return.view', 'stores.stock.adjust', 'stores.stock.approve', 'stores.value.view',
    ];

    private const VIEWS = ['stores.issue.view', 'stores.ledger.view', 'stores.report.view', 'stores.requisition.view', 'stores.return.view', 'stores.value.view'];

    /** @return list<string> the stores.* permissions $template holds at the main store */
    private function held(string $template): array
    {
        $user = $this->userWith($template, Scope::location($this->store->id));

        return $this->inTenant(fn () => collect(self::ALL)
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::location($this->store->id)))
            ->values()->all());
    }

    public function test_the_catalogue_has_every_stores_permission(): void
    {
        $this->assertSame(self::ALL, collect(StoresServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "stores.{$resource}.{$action}", $actions))
            ->sort()->values()->all());
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $this->setUpStores();

        $this->assertSame(self::ALL, $this->held('branch_manager'));
        // Stores decision 12: a storekeeper adds stock but takes none out alone (stores.stock.approve).
        $this->assertSame(array_values(array_diff(self::ALL, ['stores.issue.acknowledge', 'stores.requisition.approve', 'stores.stock.approve', 'stores.value.view'])), $this->held('storekeeper'));
        $this->assertSame(self::VIEWS, $this->held('admin'));
        $this->assertSame(self::VIEWS, $this->held('accountant'));
        $this->assertSame(self::VIEWS, $this->held('read_only_auditor'));
        // RBAC-05: buyers see what departments ask for and consume, never values.
        $this->assertSame(['stores.ledger.view', 'stores.report.view', 'stores.requisition.view'], $this->held('procurement_officer'));
        $this->assertSame(['stores.requisition.approve', 'stores.requisition.view', 'stores.stock.approve'], $this->held('approver'));
        $this->assertSame([], $this->held('cashier'));

        // RBAC-08: with the module off, the same roles grant nothing of it and its routes are closed.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('stores'));
        $this->assertSame([], $this->held('branch_manager'));
        $this->getJson('/api/v1/stores/requisitions', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
