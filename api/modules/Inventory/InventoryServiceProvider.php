<?php

namespace Modules\Inventory;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Stock\Places;

/**
 * The Inventory module (docs/modules/inventory.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:inventory`
 * (403 `module_inactive` otherwise), the `inventory.*` permissions grant
 * nothing and system roles get them only once the module is active, and
 * its listeners, document types, sync entity, dashboard sources, list
 * views and importer act only then.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public const MODULE = 'inventory';

    /** RBAC-01: the module's permission catalogue (`inventory.resource.action`). */
    public const PERMISSIONS = [
        'stock' => ['view'],
        // RBAC-05: costs and values (hidden from everyone else).
        'valuation' => ['view'],
        // INV-01, INV-02: tracking and variant links.
        'item' => ['configure'],
        'opening' => ['create'],
        // INV-11: `edit` also receives low-stock alerts.
        'reorder' => ['view', 'edit'],
        // INV-08: `approve` acts on the transfer flow (WF-08).
        'transfer' => ['view', 'create', 'approve', 'dispatch', 'receive', 'cancel'],
        'adjustment' => ['view', 'create', 'approve', 'cancel'],
        // INV-09: `count` enters counted quantities.
        'count' => ['view', 'create', 'count', 'approve', 'cancel'],
        // INV-12, INV-13: a company's costing method and negative-stock rule.
        'settings' => ['edit'],
        // INV-21: barcode labels.
        'label' => ['print'],
    ];

    public function register(): void
    {
        $this->app->scoped(Places::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
