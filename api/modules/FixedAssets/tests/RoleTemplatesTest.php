<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08, FA-29: what the system roles may do in Fixed assets
// once the module is active, and nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    private const ALL = [
        'fixed_assets.asset.manage', 'fixed_assets.asset.view', 'fixed_assets.category.manage', 'fixed_assets.depreciation.post', 'fixed_assets.depreciation.view',
        'fixed_assets.disposal.approve', 'fixed_assets.disposal.request', 'fixed_assets.label.print', 'fixed_assets.maintenance.manage', 'fixed_assets.maintenance.view',
        'fixed_assets.report.view', 'fixed_assets.revaluation.post', 'fixed_assets.settings.edit', 'fixed_assets.tax.view', 'fixed_assets.transfer.approve',
        'fixed_assets.transfer.request', 'fixed_assets.value.view',
    ];

    private const VIEWS = ['fixed_assets.asset.view', 'fixed_assets.depreciation.view', 'fixed_assets.maintenance.view', 'fixed_assets.report.view', 'fixed_assets.tax.view', 'fixed_assets.value.view'];

    /** @return list<string> the fixed_assets.* permissions $template holds at outlet A */
    private function held(string $template): array
    {
        $user = $this->userWith($template, Scope::company($this->acme->id));

        return $this->inTenant(fn () => collect(self::ALL)
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::location($this->locationA->id)))
            ->values()->all());
    }

    public function test_the_catalogue_has_every_permission(): void
    {
        $this->assertSame(self::ALL, collect(FixedAssetsServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "fixed_assets.{$resource}.{$action}", $actions))
            ->sort()->values()->all());
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $this->setUpFixedAssets();

        $this->assertSame(self::ALL, $this->held('owner'));
        $this->assertSame([...array_slice(self::VIEWS, 0, 1), 'fixed_assets.category.manage', 'fixed_assets.depreciation.view', 'fixed_assets.label.print', 'fixed_assets.maintenance.view',
            'fixed_assets.report.view', 'fixed_assets.settings.edit', 'fixed_assets.tax.view', 'fixed_assets.value.view'], $this->held('admin'));
        $this->assertSame(['fixed_assets.asset.manage', 'fixed_assets.asset.view', 'fixed_assets.disposal.request', 'fixed_assets.label.print', 'fixed_assets.maintenance.manage',
            'fixed_assets.maintenance.view', 'fixed_assets.report.view', 'fixed_assets.transfer.approve', 'fixed_assets.transfer.request', 'fixed_assets.value.view'], $this->held('branch_manager'));
        $this->assertSame(array_values(array_diff(self::ALL, ['fixed_assets.disposal.approve', 'fixed_assets.transfer.approve'])), $this->held('accountant'));
        $this->assertSame(['fixed_assets.asset.view', 'fixed_assets.disposal.approve', 'fixed_assets.transfer.approve', 'fixed_assets.value.view'], $this->held('approver'));
        $this->assertSame(self::VIEWS, $this->held('read_only_auditor'));
        $this->assertSame([], $this->held('cashier'));
        $this->assertSame([], $this->held('storekeeper'));

        // RBAC-08: with the module off, the same roles grant nothing of it and its routes are closed.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('fixed_assets'));
        $this->assertSame([], $this->held('branch_manager'));
        $this->getJson('/api/v1/fixed-assets/assets', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
