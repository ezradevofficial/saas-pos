<?php

namespace Modules\Procurement;

use App\Core\Currency\CurrencyUsage;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Support\EventReplays;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Contracts\GoodsReceiptExceptions;
use Modules\Procurement\Console\RewrapKeys;
use Modules\Procurement\CustomFields\OrderEntity;
use Modules\Procurement\CustomFields\RequisitionEntity;
use Modules\Procurement\Documents\DocumentNumbers;
use Modules\Procurement\Documents\OrderType;
use Modules\Procurement\Documents\RequisitionType;
use Modules\Procurement\Events\ProcurementEventReplay;
use Modules\Procurement\Listeners\RecordReceiptExceptions;
use Modules\Procurement\Listeners\SettleProcurementDocument;
use Modules\Procurement\Portal\PortalNotices;
use Modules\Procurement\Printing\ProcurementSource;

/**
 * The Procurement module (docs/modules/procurement.md). Registered for
 * every tenant, as Laravel boots once for all of them; what a tenant
 * reaches follows its subscription (RBAC-08): the back-office routes carry
 * `module:procurement` (403 `module_inactive` otherwise) and the supplier
 * portal checks the module of the token's tenant; the `procurement.*`
 * permissions grant nothing and system roles get them only once the module
 * is active; its document and number types are found only then.
 */
class ProcurementServiceProvider extends ServiceProvider
{
    public const MODULE = 'procurement';

    /** PRC-23: the supplier portal's rate limits. */
    public const PORTAL_LIMITER = 'procurement-portal';

    public const PORTAL_AUTH_LIMITER = 'procurement-portal-auth';

    /** RBAC-01: the module's permission catalogue (`procurement.resource.action`). */
    public const PERMISSIONS = [
        // PRC-02: `approve` acts on the requisition flow (WF-08).
        'requisition' => ['view', 'create', 'approve', 'cancel'],
        // PRC-03 to PRC-05.
        'rfq' => ['view', 'create', 'cancel'],
        // PRC-06 to PRC-12: `open` confirms an opening (committee openers), `evaluate` scores.
        'tender' => ['view', 'create', 'open', 'evaluate', 'award', 'cancel'],
        // PRC-13: RBAC-01's own example, `procurement.po.approve`.
        'po' => ['view', 'create', 'approve', 'cancel', 'print'],
        // PRC-14.
        'receipt' => ['view', 'create', 'cancel'],
        // PRC-15: `override` approves an invoice that did not match.
        'invoice' => ['view', 'create', 'approve', 'override', 'cancel'],
        // PRC-17.
        'landed_cost' => ['view', 'create', 'cancel'],
        // PRC-18.
        'performance' => ['view'],
        // PRC-01.
        'settings' => ['edit'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids). */
    public const LISTS = [
        'procurement-requisitions', 'procurement-rfqs', 'procurement-tenders', 'procurement-orders',
        'procurement-receipts', 'procurement-invoices', 'procurement-landed-costs', 'procurement-performance',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/procurement.php', 'procurement');
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        // ACC-29: GRNs, landed costs and approved invoices raised again for the Accounting backfill.
        $this->app->make(EventReplays::class)->register(self::MODULE, fn ($from, $to, $emit) => $this->app->make(ProcurementEventReplay::class)($from, $to, $emit));
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02: requisitions and POs run through flows.
        $documents = $this->app->make(DocumentTypeRegistry::class);
        $documents->register(RequisitionType::class);
        $documents->register(OrderType::class);
        Event::listen(WorkflowCompleted::class, SettleProcurementDocument::class);
        Event::listen(WorkflowCancelled::class, SettleProcurementDocument::class);

        // INV-15 (stage 7B closing review): what Inventory or Stores could not receive shows on the GRN.
        if (class_exists(GoodsReceiptExceptions::class)) {
            Event::listen(GoodsReceiptExceptions::class, RecordReceiptExceptions::class);
        }

        // NUM-01, PRC-19: their numbers.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (DocumentNumbers::types() as $type) {
            $numbers->register($type);
        }

        // CF-01, PRC-20: custom fields on requisitions and POs.
        $entities = $this->app->make(CustomFieldEntities::class);
        $entities->register(new RequisitionEntity);
        $entities->register(new OrderEntity);

        // TPL-01, PRC-12, PRC-13: the PO and the award and regret letters print from templates.
        $sources = $this->app->make(DataSources::class);

        foreach ([ProcurementSource::PO, ProcurementSource::AWARD, ProcurementSource::REGRET] as $type) {
            $sources->register(new ProcurementSource($type));
        }

        // NOT-02, ADR 009: a bidder's portal link.
        PortalNotices::register($this->app->make(EventTypes::class));

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $list) {
            $lists->register($list);
        }

        // CUR-01: once an amount is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('procurement_orders')->where('currency', $code)->exists()
            || DB::table('procurement_invoices')->where('currency', $code)->exists()
            || DB::table('procurement_quotes')->where('currency', $code)->exists()
            || DB::table('procurement_landed_cost_charges')->where('currency', $code)->exists());

        // IMP-02: an imported supplier or item used by Procurement keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('party', 'procurement_order', fn (array $ids) => DB::table('procurement_orders')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('party', 'procurement_rfq', fn (array $ids) => DB::table('procurement_rfq_suppliers')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('item', 'procurement_order', fn (array $ids) => DB::table('procurement_order_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());
        $usage->register('item', 'procurement_requisition', fn (array $ids) => DB::table('procurement_requisition_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        // PRC-23: the portal's rate limits, per session token (or IP) and per IP for sign-in and registration.
        RateLimiter::for(self::PORTAL_LIMITER, fn (Request $request) => [
            Limit::perMinute((int) config('procurement.portal.per_minute', 30))->by(self::PORTAL_LIMITER.'|token|'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute((int) config('procurement.portal.per_ip_per_minute', 120))->by(self::PORTAL_LIMITER.'|ip|'.$request->ip()),
        ]);
        RateLimiter::for(self::PORTAL_AUTH_LIMITER, fn (Request $request) => Limit::perMinute((int) config('procurement.portal.auth_per_minute', 10))
            ->by(self::PORTAL_AUTH_LIMITER.'|'.$request->ip()));

        // ADR 019: re-encrypt the tenders' keys after an APP_KEY rotation.
        if ($this->app->runningInConsole()) {
            $this->commands([RewrapKeys::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/portal.php');
        }
    }
}
