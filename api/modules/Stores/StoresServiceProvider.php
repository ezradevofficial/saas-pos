<?php

namespace Modules\Stores;

use App\Core\Currency\CurrencyUsage;
use App\Core\DocumentTemplates\DataSources;
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
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Events\StockMoved;
use Modules\Stores\Documents\DocumentNumbers;
use Modules\Stores\Documents\RequisitionType;
use Modules\Stores\Events\ConsumptionEvents;
use Modules\Stores\Listeners\ReceiveGoodsIntoStore;
use Modules\Stores\Listeners\RecordStoreValues;
use Modules\Stores\Listeners\SettleRequisition;
use Modules\Stores\Prints\IssuePrints;
use Modules\Stores\Prints\StoresDocumentSource;
use Modules\Stores\Stock\Places;

/**
 * The Stores module (docs/modules/stores.md). Registered for every tenant,
 * as Laravel boots once for all of them; what a tenant reaches follows its
 * subscription (RBAC-08): the routes carry `module:stores` (403
 * `module_inactive` otherwise), the `stores.*` permissions grant nothing
 * and system roles get them only once the module is active, and its
 * document type, number types and templates are found only then. It works
 * with only the core (its own quantity ledger); with Inventory active its
 * issues and returns move stock through Inventory's listener (STR-08).
 */
class StoresServiceProvider extends ServiceProvider
{
    public const MODULE = 'stores';

    /** RBAC-01: the module's permission catalogue (`stores.resource.action`). */
    public const PERMISSIONS = [
        // STR-02, STR-03: `approve` acts on the requisition flow (WF-08).
        'requisition' => ['view', 'create', 'approve', 'cancel'],
        // STR-04, STR-05: `create` also closes a requisition with quantities open.
        'issue' => ['view', 'create', 'acknowledge'],
        'return' => ['view', 'create'],
        // STR-10, STR-11: the store ledger and stock on hand.
        'ledger' => ['view'],
        // STR-07: opening stock and adjustments in the own ledger.
        'stock' => ['adjust'],
        // STR-12: consumption reports.
        'report' => ['view'],
        // STR-13, RBAC-05: values (hidden from everyone else).
        'value' => ['view'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids). */
    public const LISTS = ['stores-requisitions', 'stores-issues', 'stores-returns', 'stores-ledger', 'stores-stock', 'stores-consumption'];

    public function register(): void
    {
        $this->app->scoped(Places::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        // ACC-29: valued issues and returns raised again for the Accounting backfill.
        $this->app->make(EventReplays::class)->register(self::MODULE, fn ($from, $to, $emit) => $this->app->make(ConsumptionEvents::class)->replay($from, $to, $emit));
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02, STR-03: requisitions run through a flow.
        $this->app->make(DocumentTypeRegistry::class)->register(RequisitionType::class);
        Event::listen(WorkflowCompleted::class, SettleRequisition::class);
        Event::listen(WorkflowCancelled::class, SettleRequisition::class);

        // NUM-01, STR-15: their numbers.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (DocumentNumbers::types() as $type) {
            $numbers->register($type);
        }

        // STR-09: Inventory's values of Stores' issues and returns.
        Event::listen(StockMoved::class, RecordStoreValues::class);

        // STR-07: goods received at a store while Inventory is off.
        Event::listen(GoodsReceived::class, [ReceiveGoodsIntoStore::class, 'handle']);

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $list) {
            $lists->register($list);
        }

        // TPL-01, STR-14: the delivery note and the issue voucher.
        $documents = $this->app->make(DataSources::class);
        $documents->register(new StoresDocumentSource(IssuePrints::DELIVERY_NOTE));
        $documents->register(new StoresDocumentSource(IssuePrints::ISSUE_VOUCHER));

        // IMP-02: an imported item that moved in a store keeps its import from being undone.
        $this->app->make(RecordUsage::class)->register('item', 'stock_moved', fn (array $ids) => DB::table('stores_movements')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        // CUR-01: once a value is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('stores_movements')->where('currency', $code)->exists());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
