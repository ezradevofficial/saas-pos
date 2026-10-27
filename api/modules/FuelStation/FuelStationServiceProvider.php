<?php

namespace Modules\FuelStation;

use App\Core\Currency\CurrencyUsage;
use App\Core\MasterData\RecordUsage;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Payments\Tenders\SaleExtensionGrants;
use App\Core\Payments\Tenders\TenderProviders;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\FuelStation\Approvals\SettleVariance;
use Modules\FuelStation\Approvals\ShiftVarianceType;
use Modules\FuelStation\Approvals\TankVarianceType;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Sales\HandlePosEvents;
use Modules\FuelStation\Support\Numbers;
use Modules\FuelStation\Support\Stations;
use Modules\FuelStation\Sync\NozzleSource;
use Modules\FuelStation\Sync\PriceSource;
use Modules\FuelStation\Sync\StationSource;
use Modules\FuelStation\Sync\TankSource;
use Modules\FuelStation\Sync\VehicleSource;
use Modules\FuelStation\Sync\VoucherSource;
use Modules\FuelStation\Vouchers\VoucherTender;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * The Fuel station module (docs/modules/fuel-station.md). Registered for
 * every tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:fuel` (403
 * `module_inactive` otherwise), the `fuel.*` permissions grant nothing while
 * it is off, its sync sources and tender are found only then, and its
 * listeners check the module before acting for a tenant. It works with only
 * the core and POS; Inventory's stock follows through its contracts.
 */
class FuelStationServiceProvider extends ServiceProvider
{
    public const MODULE = 'fuel';

    /** RBAC-01: the module's permission catalogue (`fuel.resource.action`). */
    public const PERMISSIONS = [
        // FUEL-03
        'settings' => ['view', 'edit'],
        // FUEL-02, FUEL-04 to FUEL-07: stations, products, tanks, charts, pumps, nozzles.
        'setup' => ['view', 'manage'],
        // FUEL-08
        'price' => ['view', 'manage'],
        // FUEL-18, FUEL-19: `create` sells fuel at the till (an attendant).
        'sale' => ['view', 'create'],
        // FUEL-09, FUEL-10
        'shift' => ['view', 'open', 'close'],
        // FUEL-14, FUEL-26: `approve` decides a shift or tank variance, never one's own.
        'variance' => ['view', 'approve'],
        // FUEL-17
        'meter' => ['request_reset', 'approve_reset'],
        // FUEL-23, FUEL-24
        'dip' => ['view', 'record'],
        // FUEL-25
        'delivery' => ['view', 'record'],
        // FUEL-20
        'vehicle' => ['view', 'manage'],
        // FUEL-21
        'voucher' => ['view', 'manage', 'redeem'],
        // FUEL-34
        'report' => ['view'],
    ];

    public function register(): void
    {
        $this->app->scoped(Stations::class);
        $this->app->scoped(Present::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, FUEL-14, FUEL-26: shift and tank variances are decided through the workflow engine.
        $types = $this->app->make(DocumentTypeRegistry::class);
        $types->register(ShiftVarianceType::class);
        $types->register(TankVarianceType::class);
        Event::listen(WorkflowCompleted::class, SettleVariance::class);
        Event::listen(WorkflowCancelled::class, SettleVariance::class);

        // NUM-01, FUEL-35: settlements, reconciliations and deliveries are numbered per branch.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (Numbers::types() as $type) {
            $numbers->register($type);
        }

        // FUEL-21: the `fuel_voucher` tender, and the POS events that record fuel sales and redeem and give back vouchers.
        $this->app->make(TenderProviders::class)->register($this->app->make(VoucherTender::class));

        // FUEL-18: `fuel.sale.create` rings sales carrying `extensions.fuel` for the lines that are fuel products only.
        $this->app->make(SaleExtensionGrants::class)->register('fuel', self::MODULE, 'fuel.sale.create', fn (array $itemIds) => DB::table('fuel_products')->whereIn('item_id', $itemIds)->pluck('item_id')->all());

        if (class_exists(SaleCompleted::class)) {
            Event::listen(SaleCompleted::class, [HandlePosEvents::class, 'completed']);
            Event::listen(SaleVoided::class, [HandlePosEvents::class, 'voided']);
            Event::listen(SaleRefunded::class, [HandlePosEvents::class, 'refunded']);
        }

        // FUEL-22, NFR-04: what the till pulls from the module. Staff rows carry `fuel.` permissions.
        $sources = $this->app->make(SyncSources::class);

        foreach ([new StationSource, new NozzleSource, new PriceSource, new TankSource, new VehicleSource, new VoucherSource] as $source) {
            $sources->register($source);
        }

        $sources->tillPermissionPrefix('fuel.');

        // CUR-01: amounts stored in a currency lock its decimals.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('fuel_prices')->where('currency', $code)->exists()
            || DB::table('fuel_deliveries')->where('currency', $code)->exists()
            || DB::table('fuel_vouchers')->where('currency', $code)->exists()
            || DB::table('fuel_sales')->where('currency', $code)->exists());

        // IMP-02: an imported item that is a fuel product keeps its import from being undone.
        $this->app->make(RecordUsage::class)->register('item', 'fuel_product', fn (array $ids) => DB::table('fuel_products')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
