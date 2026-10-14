<?php

namespace Modules\Inventory;

use App\Core\Currency\CurrencyUsage;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberTypes;
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
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Dashboards\LowStock;
use Modules\Inventory\Dashboards\StockValue;
use Modules\Inventory\Documents\AdjustmentType;
use Modules\Inventory\Documents\CountType;
use Modules\Inventory\Documents\DocumentNumbers;
use Modules\Inventory\Documents\TransferType;
use Modules\Inventory\Events\StockLow;
use Modules\Inventory\Imports\OpeningStockImporter;
use Modules\Inventory\Labels\LabelSource;
use Modules\Inventory\Listeners\MoveStockForSale;
use Modules\Inventory\Listeners\NotifyLowStock;
use Modules\Inventory\Listeners\RecordGoodsReceived;
use Modules\Inventory\Listeners\SettleInventoryDocument;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\OpeningStock;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Valuer;
use Modules\Inventory\Sync\StockSource;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * The Inventory module (docs/modules/inventory.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:inventory`
 * (403 `module_inactive` otherwise), the `inventory.*` permissions grant
 * nothing and system roles get them only once the module is active, and
 * its document types, number types, sync entity, dashboard sources and
 * notifications are found only then; its listeners check the module
 * before acting for a tenant.
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

    /** LAY-04: the module's lists that may have saved views (the web list ids). */
    public const LISTS = [
        'inventory-stock', 'inventory-movements', 'inventory-transfers', 'inventory-adjustments',
        'inventory-counts', 'inventory-reorder-rules', 'inventory-valuation',
    ];

    public function register(): void
    {
        $this->app->scoped(Places::class);
        $this->app->scoped(Valuer::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02: transfers, adjustments and count variances run through flows.
        $documents = $this->app->make(DocumentTypeRegistry::class);
        $documents->register(TransferType::class);
        $documents->register(AdjustmentType::class);
        $documents->register(CountType::class);
        Event::listen(WorkflowCompleted::class, SettleInventoryDocument::class);
        Event::listen(WorkflowCancelled::class, SettleInventoryDocument::class);

        // NUM-01, INV-23: their numbers.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (DocumentNumbers::types() as $type) {
            $numbers->register($type);
        }

        // INV-14: POS sales move stock (when both modules are active, checked per tenant).
        foreach ([SaleCompleted::class, SaleVoided::class, SaleRefunded::class] as $event) {
            Event::listen($event, [MoveStockForSale::class, 'handle']);
        }

        // INV-15: goods received by Procurement (the contract defined here).
        Event::listen(GoodsReceived::class, [RecordGoodsReceived::class, 'handle']);

        // INV-11, NOT-02: low-stock alerts.
        Event::listen(StockLow::class, [NotifyLowStock::class, 'handle']);
        $this->app->make(EventTypes::class)->register(new EventType(
            key: NotifyLowStock::EVENT,
            placeholders: [
                'item_name' => 'Soda 500 ml', 'item_code' => 'SODA-500', 'location_name' => 'Main store',
                'quantity' => '4', 'unit' => 'PCS', 'reorder_level' => '10', 'reorder_quantity' => '48',
            ],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'inventory.notifications.stock_low',
        ));

        // INV-17, NFR-04: stock on hand at the till's location.
        $this->app->make(SyncSources::class)->register(new StockSource);

        // INV-22, LAY-01: dashboard widgets.
        $dashboards = $this->app->make(DashboardSources::class);
        $dashboards->register(new LowStock);
        $dashboards->register(new StockValue);

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $list) {
            $lists->register($list);
        }

        // IMP-01, IMP-02, INV-18: opening stock from a file; undone only while nothing moved since.
        $this->app->make(ImportRegistry::class)->register(new OpeningStockImporter);
        $this->app->make(RecordUsage::class)->register('inventory_opening', 'stock_moved', fn (array $ids) => Movement::query()->whereIn('id', $ids)->get()
            ->filter(fn (Movement $movement) => app(OpeningStock::class)->used($movement))->pluck('id')->map(fn ($id) => (string) $id)->all());

        // IMP-02: an imported item with stock movements keeps its import from being undone.
        $this->app->make(RecordUsage::class)->register('item', 'stock_moved', fn (array $ids) => DB::table('inventory_movements')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        // CUR-01: once a cost is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('inventory_cost_states')->where('currency', $code)->exists()
            || DB::table('inventory_movements')->where('currency', $code)->exists());

        // TPL-01, INV-21: barcode labels printed through document templates.
        $this->app->make(DataSources::class)->register(new LabelSource);

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
