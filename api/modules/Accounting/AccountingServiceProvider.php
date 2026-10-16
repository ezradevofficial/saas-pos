<?php

namespace Modules\Accounting;

use App\Core\Currency\CurrencyUsage;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Fiscal\FiscalSources;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\Events\ModuleActivated;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Console\BackfillPostings;
use Modules\Accounting\Console\PostSalesSummaries;
use Modules\Accounting\Console\RunRecurringJournals;
use Modules\Accounting\Documents\AccountingDocumentSource;
use Modules\Accounting\Documents\Documents;
use Modules\Accounting\Events\DocumentPosted;
use Modules\Accounting\Fiscal\AccountingFiscalSource;
use Modules\Accounting\Imports\ChartOfAccountsImporter;
use Modules\Accounting\Imports\OpeningBalancesImporter;
use Modules\Accounting\Integrations\QuoteInvoices;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\JournalType;
use Modules\Accounting\Listeners\PostModuleEvent;
use Modules\Accounting\Listeners\QueueFiscalDocument;
use Modules\Accounting\Listeners\SeedModuleRules;
use Modules\Accounting\Listeners\SettleJournal;
use Modules\Accounting\Payments\Payments;
use Modules\CRM\Events\QuoteConverted;
use Modules\FixedAssets\Events\AssetCapitalised;
use Modules\FixedAssets\Events\AssetDisposed;
use Modules\FixedAssets\Events\AssetImpaired;
use Modules\FixedAssets\Events\AssetRevalued;
use Modules\FixedAssets\Events\DepreciationPosted;
use Modules\FixedAssets\Events\DepreciationReversed;
use Modules\Inventory\Events\StockMoved;
use Modules\Loyalty\Events\CreditAdjusted;
use Modules\Loyalty\Events\CreditCharged;
use Modules\Loyalty\Events\CreditPaymentReceived;
use Modules\Loyalty\Events\GiftCardExpired;
use Modules\Loyalty\Events\GiftCardRedeemed;
use Modules\Loyalty\Events\GiftCardSold;
use Modules\Loyalty\Events\PointsAdjusted;
use Modules\Loyalty\Events\PointsEarned;
use Modules\Loyalty\Events\PointsExpired;
use Modules\Loyalty\Events\PointsRedeemed;
use Modules\Manufacturing\Events\ProductionValued;
use Modules\Payroll\Events\PayrollPosted;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\LandedCostAllocated;
use Modules\Procurement\Events\SupplierInvoiceApproved;
use Modules\Stores\Events\StoreConsumptionValued;

/**
 * The Accounting module (docs/modules/accounting.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:accounting`
 * (403 `module_inactive` otherwise), and the Pro features
 * `module:accounting_pro` as well (decision 1); the `accounting.*`
 * permissions grant nothing and system roles get them only once the module
 * is active; its document and numbered types are found only then.
 */
class AccountingServiceProvider extends ServiceProvider
{
    public const MODULE = 'accounting';

    /** Decision 1: multi-currency, revaluation, budgets and consolidation (concept note 8.4). */
    public const PRO = 'accounting_pro';

    /** RBAC-01: the module's permission catalogue (`accounting.resource.action`). */
    public const PERMISSIONS = [
        'account' => ['view', 'manage'],
        'posting_rule' => ['view', 'manage'],
        'journal' => ['view', 'create', 'approve', 'reverse'],
        'invoice' => ['view', 'create', 'post'],
        'payment' => ['view', 'create'],
        'bank' => ['view', 'manage', 'reconcile'],
        'budget' => ['view', 'manage'],
        'period' => ['view', 'close', 'reopen'],
        'report' => ['view', 'consolidate'],
    ];

    /** ACC-25, ACC-27: the POS and Inventory events Accounting posts. */
    private const INTEGRATION_EVENTS = [
        SaleCompleted::class, SaleVoided::class, SaleRefunded::class,
        StockMoved::class,
        // ACC-31: Stores' valued issues and returns (its stock moves post nothing: the same movement).
        StoreConsumptionValued::class,
        // ACC-32: Procurement's approved supplier invoices (its GRNs post from StockMoved, procurement.goods_receipt).
        SupplierInvoiceApproved::class,
        // Stage 7B closing review: landed cost bills and the landed cost of lines no stock journal carries.
        LandedCostAllocated::class, GoodsReceiptPosted::class,
        // ACC-35: Manufacturing's valued documents (its stock moves post nothing: the same movement).
        ProductionValued::class,
        // ACC-34: Fixed assets (docs/modules/fixed-assets.md FA-22).
        AssetCapitalised::class, DepreciationPosted::class, DepreciationReversed::class, AssetRevalued::class, AssetImpaired::class, AssetDisposed::class,
        // PAY-22: posted payroll runs (journals per department and pay currency).
        PayrollPosted::class,
    ];

    /** ACC-28: the Loyalty ledger events Accounting posts. */
    private const LOYALTY_EVENTS = [
        GiftCardSold::class, GiftCardRedeemed::class, GiftCardExpired::class,
        PointsEarned::class, PointsRedeemed::class, PointsExpired::class,
        PointsAdjusted::class, CreditCharged::class, CreditPaymentReceived::class,
        CreditAdjusted::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/accounting.php', 'accounting');
    }

    public function boot(): void
    {
        $modules = $this->app->make(ModuleRegistry::class);
        $modules->register(self::MODULE);
        $modules->register(self::PRO);
        // SUB-07: Accounting is sold as Essentials or Pro; Pro also unlocks the Pro features.
        $modules->soldAs(self::MODULE, ['accounting_essentials', 'accounting_pro']);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NUM-01: journals, documents, receipts and payments, numbered per year.
        $types = $this->app->make(DocumentNumberTypes::class);
        $types->register(new DocumentNumberType(JournalPoster::NUMBER_TYPE, self::MODULE, 'JV-{YYYY}-{00001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'accounting.numbering.journal'));

        foreach ([...Documents::NUMBERING, ...Payments::NUMBERING] as $kind => [$key, $pattern]) {
            $types->register(new DocumentNumberType($key, self::MODULE, $pattern, NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: "accounting.numbering.{$kind}"));
        }

        // CUR-01: once an amount is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('acc_journal_lines')->where('currency', $code)->exists()
            || DB::table('acc_documents')->where('currency', $code)->exists()
            || DB::table('acc_payments')->where('currency', $code)->exists());

        // IMP-02: an imported party or item used by the ledger keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('party', 'accounting_document', fn (array $ids) => DB::table('acc_documents')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('party', 'accounting_payment', fn (array $ids) => DB::table('acc_payments')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('item', 'accounting_document', fn (array $ids) => DB::table('acc_document_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        // ACC-24 (review item 13): a module switched on later gets its default posting rules in charts that exist.
        Event::listen(ModuleActivated::class, SeedModuleRules::class);

        // ACC-04, WF-01: manual journals go through their flow.
        $this->app->make(DocumentTypeRegistry::class)->register(JournalType::class);
        Event::listen(WorkflowCompleted::class, SettleJournal::class);
        Event::listen(WorkflowCancelled::class, SettleJournal::class);

        // ACC-19: customer invoices and credit notes go to the core fiscal queue.
        $this->app->make(FiscalSources::class)->register(AccountingFiscalSource::KEY, AccountingFiscalSource::class);
        Event::listen(DocumentPosted::class, [QueueFiscalDocument::class, 'handle']);

        // TPL-01: invoices, credit notes and statements print from templates.
        $documents = $this->app->make(DataSources::class);

        foreach (['accounting.invoice', 'accounting.credit_note', 'accounting.statement'] as $type) {
            $documents->register(new AccountingDocumentSource($type));
        }

        // LAY-04: the lists that may have saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (['accounting-accounts', 'accounting-journals', 'accounting-documents', 'accounting-payments'] as $list) {
            $lists->register($list);
        }

        // ACC-20, IMP-01: the chart of accounts and opening balances come in through the import framework.
        $imports = $this->app->make(ImportRegistry::class);
        $imports->register($this->app->make(ChartOfAccountsImporter::class));
        $imports->register($this->app->make(OpeningBalancesImporter::class));

        // ACC-25 to ACC-28: other modules' events post through the posting rules, queued, when both modules are active.
        foreach ([...self::INTEGRATION_EVENTS, ...self::LOYALTY_EVENTS] as $event) {
            if (class_exists($event)) {
                Event::listen($event, PostModuleEvent::class);
            }
        }

        // CRM-24: an accepted CRM quote converted to an invoice becomes a draft customer invoice, in the converting transaction.
        if (class_exists(QuoteConverted::class)) {
            Event::listen(QuoteConverted::class, [QuoteInvoices::class, 'handle']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RunRecurringJournals::class, PostSalesSummaries::class, BackfillPostings::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // ACC-06: recurring journals due today, every morning.
            $schedule->command('accounting:recurring')->dailyAt('05:00')->withoutOverlapping()->onOneServer();
            // ACC-26: the daily summaries of POS sales for days that have ended.
            $schedule->command('accounting:post-sales-summaries')->hourlyAt(15)->withoutOverlapping()->onOneServer();
        });

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
