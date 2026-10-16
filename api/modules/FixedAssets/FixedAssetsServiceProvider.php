<?php

namespace Modules\FixedAssets;

use App\Core\Currency\CurrencyUsage;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemTypeGuards;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Support\EventReplays;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\FixedAssets\Console\MaintenanceRemindersCommand;
use Modules\FixedAssets\CustomFields\AssetEntity;
use Modules\FixedAssets\Dashboards\Widgets;
use Modules\FixedAssets\Documents\DisposalType;
use Modules\FixedAssets\Documents\TransferType;
use Modules\FixedAssets\Http\Lists\AssetList;
use Modules\FixedAssets\Imports\AssetsImporter;
use Modules\FixedAssets\Labels\LabelSource;
use Modules\FixedAssets\Listeners\CancelDraftsOfReceipt;
use Modules\FixedAssets\Listeners\CapitaliseFromProcurement;
use Modules\FixedAssets\Listeners\SettleFixedAssetDocument;
use Modules\FixedAssets\Maintenance\Reminders;
use Modules\FixedAssets\Models\ItemCategory;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\Numbers;
use Modules\FixedAssets\TaxBook\TaxClasses;
use Modules\Procurement\Events\GoodsReceiptCancelled;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\SupplierInvoiceApproved;

/**
 * The Fixed assets module (docs/modules/fixed-assets.md). Registered for
 * every tenant, as Laravel boots once for all of them; what a tenant
 * reaches follows its subscription (RBAC-08): the routes carry
 * `module:fixed_assets` (403 `module_inactive` otherwise), the
 * `fixed_assets.*` permissions grant nothing and system roles get them
 * only once the module is active, and its document types, number types,
 * custom field entity, label template, importer and dashboard sources are
 * found only then. It works with only the core; Accounting posts its
 * events (FA-22) and its listener hears Procurement's (FA-21).
 */
class FixedAssetsServiceProvider extends ServiceProvider
{
    public const MODULE = 'fixed_assets';

    /** RBAC-01: the module's permission catalogue (`fixed_assets.resource.action`). */
    public const PERMISSIONS = [
        // FA-04 to FA-06, FA-11, FA-18, FA-21: the register, documents, readings and flagged items.
        'asset' => ['view', 'manage'],
        // FA-02.
        'category' => ['manage'],
        // FA-12, FA-13: runs (preview, post, reverse).
        'depreciation' => ['view', 'post'],
        // FA-14: `approve` acts on the transfer flow (WF-08).
        'transfer' => ['request', 'approve'],
        // FA-17: `approve` acts on the disposal flow.
        'disposal' => ['request', 'approve'],
        // FA-15, FA-16.
        'revaluation' => ['post'],
        // FA-19.
        'maintenance' => ['view', 'manage'],
        // FA-25.
        'label' => ['print'],
        // FA-26.
        'report' => ['view'],
        // RBAC-05: costs and book values; everything else needs nothing more.
        'value' => ['view'],
        // FA-01.
        'settings' => ['edit'],
        // FA-20.
        'tax' => ['view'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids => their definition). */
    public const LISTS = [
        'fixed-assets-register' => AssetList::class,
        'fixed-assets-transfers' => null,
        'fixed-assets-disposals' => null,
        'fixed-assets-runs' => null,
        'fixed-assets-work-orders' => null,
        'fixed-assets-revaluations' => null,
    ];

    public function register(): void
    {
        $this->app->singleton(TaxClasses::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);
        // ACC-29: what the module raised to the ledger, raised again for the Accounting backfill.
        $this->app->make(EventReplays::class)->register(self::MODULE, fn ($from, $to, $emit) => $this->app->make(Postings::class)->replay($from, $to, $emit));

        // WF-01, WF-02, FA-14, FA-17: transfers and disposals run through flows.
        $types = $this->app->make(DocumentTypeRegistry::class);
        $types->register(TransferType::class);
        $types->register(DisposalType::class);
        Event::listen(WorkflowCompleted::class, SettleFixedAssetDocument::class);
        Event::listen(WorkflowCancelled::class, SettleFixedAssetDocument::class);

        // FA-21: Procurement's receipts and invoices of items flagged as assets become draft assets.
        foreach ([GoodsReceiptPosted::class, SupplierInvoiceApproved::class] as $event) {
            if (class_exists($event)) {
                Event::listen($event, CapitaliseFromProcurement::class);
            }
        }

        // FA-21: an item flagged as an asset stays non-stock while the module is active (unflag it first).
        $this->app->make(ItemTypeGuards::class)->register(function (Item $item, string $type) {
            if ($type === 'stock' && $this->app->make(ModuleRegistry::class)->isActive(self::MODULE)
                && ItemCategory::query()->where('item_id', $item->id)->whereNull('archived_at')->exists()) {
                throw FaError::make('item_is_asset', field: 'type');
            }
        });

        if (class_exists(GoodsReceiptCancelled::class)) {
            Event::listen(GoodsReceiptCancelled::class, CancelDraftsOfReceipt::class);
        }

        // NUM-01, FA-27.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (Numbers::types() as $type) {
            $numbers->register($type);
        }

        // CF-01, FA-23: assets carry custom fields (offered while the module is active, CustomFieldEntity::module(), RBAC-08).
        $this->app->make(CustomFieldEntities::class)->register(new AssetEntity);

        // TPL-01, FA-25: asset labels print through document templates.
        $this->app->make(DataSources::class)->register(new LabelSource);

        // IMP-01, FA-24: the opening register.
        $this->app->make(ImportRegistry::class)->register(new AssetsImporter);

        // LAY-01, FA-28: dashboard widgets.
        $dashboards = $this->app->make(DashboardSources::class);

        foreach ([Widgets::BOOK_VALUE, Widgets::MAINTENANCE_DUE, Widgets::FULLY_DEPRECIATED] as $key) {
            $dashboards->register(new Widgets($key));
        }

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $key => $definition) {
            $lists->register($key, $definition);
        }

        // NOT-02, FA-19: maintenance falling due.
        Reminders::register($this->app->make(EventTypes::class));

        // IMP-02: an imported item, party or user an asset uses keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('item', 'fixed_assets_item', fn (array $ids) => DB::table('fa_item_categories')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());
        $usage->register('party', 'fixed_assets_asset', fn (array $ids) => DB::table('fa_assets')->whereIn('supplier_id', $ids)->distinct()->pluck('supplier_id')->all());
        $usage->register('party', 'fixed_assets_disposal', fn (array $ids) => DB::table('fa_disposals')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('party', 'fixed_assets_work_order', fn (array $ids) => DB::table('fa_work_orders')->whereIn('vendor_id', $ids)->distinct()->pluck('vendor_id')->all());
        $usage->register('party', 'fixed_assets_posting', fn (array $ids) => DB::table('fa_postings')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());

        // CUR-01: once an amount is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('fa_assets')->where('currency', $code)->exists()
            || DB::table('fa_disposals')->where('currency', $code)->exists()
            || DB::table('fa_work_orders')->where('currency', $code)->exists());

        if ($this->app->runningInConsole()) {
            $this->commands([MaintenanceRemindersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // FA-19: maintenance falling due, reminded once a day.
            $schedule->command('fixed-assets:maintenance-reminders')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
        });

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
