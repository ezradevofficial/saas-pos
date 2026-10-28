<?php

namespace Modules\VanSales;

use App\Core\Currency\CurrencyUsage;
use App\Core\Rbac\Events\ModuleActivated;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\VanSales\Console\PurgePositionsCommand;
use Modules\VanSales\Listeners\HandleSales;
use Modules\VanSales\Reconciliation\ReconciliationType;
use Modules\VanSales\Reconciliation\SettleReconciliation;
use Modules\VanSales\Sync\Sources\AccountsSource;
use Modules\VanSales\Sync\Sources\LoadsSource;
use Modules\VanSales\Sync\Sources\RoutesSource;
use Modules\VanSales\Sync\Sources\RunsSource;
use Modules\VanSales\Sync\Sources\VanSource;

/**
 * The Van sales module (docs/modules/van-sales.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:van_sales`
 * (403 `module_inactive` otherwise), the `van_sales.*` permissions grant
 * nothing and system roles get them only once the module is active, its sync
 * sources are served only then and its listener does nothing without it.
 * It is sold as a catalogue module (per device, requires POS) and works with
 * the core and POS alone: sales are normal POS sales, Inventory switches on
 * load-out and unload by transfer.
 */
class VanSalesServiceProvider extends ServiceProvider
{
    public const MODULE = 'van_sales';

    /** RBAC-01: the module's permission catalogue (`van_sales.resource.action`). */
    public const PERMISSIONS = [
        // VAN-02.
        'van' => ['view', 'manage'],
        // VAN-03, VAN-04, VAN-05.
        'route' => ['view', 'manage'],
        // VAN-07, VAN-08.
        'load' => ['view', 'create', 'release', 'confirm'],
        // VAN-06.
        'run' => ['view', 'start'],
        // VAN-09, VAN-24.
        'visit' => ['record', 'track'],
        // VAN-17.
        'collection' => ['view', 'record', 'void'],
        // VAN-11 to VAN-13.
        'credit' => ['view', 'review'],
        // VAN-18, VAN-21.
        'reconciliation' => ['view', 'submit', 'approve'],
        // VAN-23.
        'unload' => ['receive'],
        // VAN-24: a company's GPS and credit settings.
        'settings' => ['edit'],
        // VAN-25.
        'report' => ['view'],
    ];

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NFR-04: what the van's device pulls from the module (core's sync API).
        $sources = $this->app->make(SyncSources::class);

        foreach ([new VanSource, new RoutesSource, new AccountsSource, new LoadsSource, new RunsSource] as $source) {
            $sources->register($source);
        }

        // VAN-05: the till's staff list carries `van_sales.` permissions, or a rep cannot start the day.
        $sources->tillPermissionPrefix('van_sales.');

        // VAN-10, VAN-16: sales and refunds at a van join its run; a void takes them back.
        Event::listen(SaleCompleted::class, [HandleSales::class, 'completed']);
        Event::listen(SaleVoided::class, [HandleSales::class, 'voided']);
        Event::listen(SaleRefunded::class, [HandleSales::class, 'refunded']);

        // WF-01, VAN-21: a count with a variance runs through a flow.
        $this->app->make(DocumentTypeRegistry::class)->register(ReconciliationType::class);
        Event::listen(WorkflowCompleted::class, SettleReconciliation::class);
        Event::listen(WorkflowCancelled::class, SettleReconciliation::class);

        // ONB-02: a tenant from before the module gets its two role templates when it subscribes.
        Event::listen(ModuleActivated::class, function (ModuleActivated $event) {
            if ($event->module === self::MODULE) {
                $this->app->make(RoleTemplates::class)->ensure(Tenant::query()->findOrFail($event->tenantId), ['van_rep', 'route_supervisor']);
            }
        });

        // CUR-01: once a sale or collection is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('vs_sales')->where('currency', $code)->exists()
            || DB::table('vs_collections')->where('currency', $code)->exists());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->commands([PurgePositionsCommand::class]);

        // VAN-24: check-in positions are cleared after the company's retention.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('van-sales:purge-positions')->dailyAt('03:20')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
