<?php

namespace Modules\Manufacturing;

use App\Core\Currency\CurrencyUsage;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Support\EventReplays;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Events\StockMoved;
use Modules\Manufacturing\Backflush\BackflushSales;
use Modules\Manufacturing\Boms\Versions;
use Modules\Manufacturing\CustomFields\BomEntity;
use Modules\Manufacturing\CustomFields\OrderEntity;
use Modules\Manufacturing\Events\ValuedEvents;
use Modules\Manufacturing\Imports\BomLinesImporter;
use Modules\Manufacturing\Listeners\RecordValues;
use Modules\Manufacturing\Listeners\SettleOrder;
use Modules\Manufacturing\Orders\DocumentNumbers;
use Modules\Manufacturing\Orders\OrderType;
use Modules\Manufacturing\Prints\ManufacturingDocumentSource;
use Modules\Manufacturing\Prints\OrderPrints;
use Modules\Manufacturing\Support\Places;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * The Manufacturing module (docs/modules/manufacturing.md). Registered for
 * every tenant, as Laravel boots once for all of them; what a tenant
 * reaches follows its subscription (RBAC-08): the routes carry
 * `module:manufacturing` (403 `module_inactive` otherwise), the
 * `manufacturing.*` permissions grant nothing and system roles get them
 * only once the module is active, and its document type, number types,
 * templates, custom field entities and importer are found only then. It
 * works with only the core (quantities); with Inventory active its
 * documents move and value stock through Inventory's listener (MFG-15),
 * and with POS too, sold recipes backflush their ingredients (MFG-07).
 */
class ManufacturingServiceProvider extends ServiceProvider
{
    public const MODULE = 'manufacturing';

    /** RBAC-01: the module's permission catalogue (`manufacturing.resource.action`). */
    public const PERMISSIONS = [
        // MFG-02 to MFG-05, MFG-23: BOMs, recipes and their versions (and the BOM import).
        'bom' => ['view', 'manage'],
        // MFG-10, MFG-11, MFG-14: `approve` acts on the release flow (WF-08).
        'order' => ['view', 'create', 'approve', 'release', 'close', 'cancel'],
        // MFG-12: materials to and from an order.
        'material' => ['issue', 'return'],
        // MFG-13: good output, scrap, by-products and labour.
        'production' => ['report'],
        // MFG-06, MFG-07, MFG-18, MFG-19: reports.
        'report' => ['view'],
        // MFG-21, RBAC-05: costs and values (hidden from everyone else).
        'cost' => ['view'],
        // MFG-29: a company's rates and release approval.
        'settings' => ['edit'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids). */
    public const LISTS = [
        'manufacturing-boms', 'manufacturing-orders', 'manufacturing-consumption', 'manufacturing-yield',
        'manufacturing-backflushes', 'manufacturing-recipe-costing',
    ];

    public function register(): void
    {
        $this->app->scoped(Places::class);
        $this->app->scoped(Versions::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);
        // ACC-29: valued documents raised again for the Accounting backfill.
        $this->app->make(EventReplays::class)->register(self::MODULE, fn ($from, $to, $emit) => $this->app->make(ValuedEvents::class)->replay($from, $to, $emit));

        // WF-01, WF-02, MFG-11: releases that need approval run through a flow.
        $this->app->make(DocumentTypeRegistry::class)->register(OrderType::class);
        Event::listen(WorkflowCompleted::class, SettleOrder::class);
        Event::listen(WorkflowCancelled::class, SettleOrder::class);

        // NUM-01, MFG-25: their numbers.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (DocumentNumbers::types() as $type) {
            $numbers->register($type);
        }

        // MFG-07, MFG-08: POS sales backflush their recipes (when Manufacturing and Inventory are active, checked per tenant).
        foreach ([SaleCompleted::class, SaleVoided::class, SaleRefunded::class] as $event) {
            Event::listen($event, [BackflushSales::class, 'handle']);
        }

        // MFG-15, MFG-16: Inventory's values of Manufacturing's documents.
        Event::listen(StockMoved::class, RecordValues::class);

        // CF-01, MFG-22: BOMs and orders carry custom fields.
        $entities = $this->app->make(CustomFieldEntities::class);
        $entities->register(new BomEntity);
        $entities->register(new OrderEntity);

        // IMP-01, MFG-23: BOM lines from a file.
        $this->app->make(ImportRegistry::class)->register(new BomLinesImporter);

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $list) {
            $lists->register($list);
        }

        // TPL-01, MFG-24: the production order and the pick list.
        $documents = $this->app->make(DataSources::class);
        $documents->register(new ManufacturingDocumentSource(OrderPrints::PRODUCTION_ORDER));
        $documents->register(new ManufacturingDocumentSource(OrderPrints::PICK_LIST));

        // IMP-02: an imported item used by a BOM or an order keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('item', 'manufacturing_bom', fn (array $ids) => DB::table('mfg_bom_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')
            ->merge(DB::table('mfg_boms')->whereIn('item_id', $ids)->distinct()->pluck('item_id'))->unique()->values()->all());
        $usage->register('item', 'manufacturing_order', fn (array $ids) => DB::table('mfg_orders')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        // CUR-01: once a value is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('mfg_orders')->where('currency', $code)->exists()
            || DB::table('mfg_backflushes')->where('currency', $code)->exists());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
