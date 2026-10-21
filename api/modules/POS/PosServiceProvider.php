<?php

namespace Modules\POS;

use App\Core\Configuration\ConfigKinds;
use App\Core\Currency\CurrencyUsage;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Fiscal\FiscalSources;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Onboarding\Checklist\Checklist;
use App\Core\Onboarding\Sample\SampleDependents;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\SubjectSource;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Support\EventReplays;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Events\DeviceUnpaired;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\POS\Dashboards\SalesByDay;
use Modules\POS\Dashboards\SalesToday;
use Modules\POS\Documents\ReceiptSource;
use Modules\POS\Events\PosEventReplay;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Fiscal\FiscalCustomerRetention;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Layout\PosLayout;
use Modules\POS\Listeners\ApplyPaymentSettlement;
use Modules\POS\Listeners\LinkMobileMoneyPayments;
use Modules\POS\Listeners\QueueFiscalDocument;
use Modules\POS\Listeners\RetireDeviceRanges;
use Modules\POS\Sync\CoreOverrides;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Sync\Sellability;
use Modules\POS\Sync\Sources\NumberRangeSource;
use Modules\POS\Sync\Sources\OpenShiftSource;
use Modules\POS\Sync\Sources\PosLayoutSource;
use Modules\POS\Sync\Sources\TemplateSource;
use Modules\POS\Webhooks\PosWebhookEvents;

/**
 * The POS module (docs/modules/pos.md). Registered for every tenant, as
 * Laravel boots once for all of them; what a tenant reaches follows its
 * subscription (RBAC-08): the routes carry `module:pos` (403
 * `module_inactive` otherwise), the `pos.*` permissions grant nothing and
 * system roles get them only once the module is active (ModuleRegistry),
 * and its numbered document types are listed only then.
 */
class PosServiceProvider extends ServiceProvider
{
    public const MODULE = 'pos';

    /** RBAC-01: the module's permission catalogue (`pos.resource.action`). */
    public const PERMISSIONS = [
        // TPL-04: `share` emails a receipt and makes a public link to it.
        'sale' => ['view', 'create', 'print', 'void', 'refund', 'review', 'share'],
        'shift' => ['view', 'open', 'close', 'manage'],
        'cash' => ['move'],
        'price' => ['override'],
        'discount' => ['give'],
        // AUTH-06: signs in at the tills where the role is held (core's sync.sign_in_permission).
        'till' => ['sign_in'],
        // LAY-05: the sell screen's layout (the `pos_layout` configuration kind).
        'layout' => ['view', 'edit', 'publish'],
    ];

    /** @param list<string> $ids @return list<array{type: string, id: string, used_by: string}> */
    private static function layoutsNaming(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pattern = '('.implode('|', array_map(fn (string $id) => preg_quote($id, '/'), $ids)).')';

        return DB::table('config_versions')->join('config_documents', 'config_documents.id', '=', 'config_versions.document_id')
            ->where('config_documents.kind', PosLayout::KEY)
            ->whereNull('config_versions.archived_at')->whereNull('config_versions.discarded_at')
            ->whereRaw('config_versions.payload::text ~ ?', [$pattern])
            ->pluck('config_versions.id')
            ->map(fn ($id) => ['type' => 'item', 'id' => (string) $id, 'used_by' => 'pos_layout'])->all();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/pos.php', 'pos');
        $this->callAfterResolving(PrivacySubjects::class, function (PrivacySubjects $subjects) {
            // Review H1: sales to the customer (customer_id), never those a user rang up as cashier.
            $subjects->register(new SubjectSource(subjectType: 'customer', table: 'pos_sales', match: 'customer_id', related: true));
            $subjects->retain('customer', fn (string $partyId) => app(FiscalCustomerRetention::class)->for($partyId));
        });
        // AUTH-07, AUTH-08: core's PIN verifier once it is installed, else nothing is provable (held).
        $this->app->bindIf(OverrideVerifier::class, CoreOverrides::class);
        $this->app->scoped(Sellability::class);
    }

    public function boot(): void
    {
        // HOOK-01: the module's public webhook events (sent only while it is active).
        PosWebhookEvents::register($this->app->make(WebhookCatalogue::class));

        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // Stage 7B: the module's events raised again for a period (a later module's backfill).
        $this->app->make(EventReplays::class)->register(self::MODULE, fn ($from, $to, $emit) => $this->app->make(PosEventReplay::class)($from, $to, $emit));

        // NUM-01, NUM-02: receipts and refund receipts, drawn from device ranges.
        $types = $this->app->make(DocumentNumberTypes::class);
        $types->register(new DocumentNumberType('pos.receipt', self::MODULE, 'R-{LOCATION}-{000001}', NumberFormat::RESET_NEVER, ['BRANCH', 'LOCATION', 'DEVICE'], ranged: true, langKey: 'pos.numbering.receipt'));
        $types->register(new DocumentNumberType('pos.refund', self::MODULE, 'RF-{LOCATION}-{000001}', NumberFormat::RESET_NEVER, ['BRANCH', 'LOCATION', 'DEVICE'], ranged: true, langKey: 'pos.numbering.refund'));

        // CUR-01: once a sale, payment or float is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('pos_sales')->where('currency', $code)->exists()
            || DB::table('pos_sale_payments')->where('currency', $code)->exists()
            || DB::table('pos_shift_balances')->where('currency', $code)->exists()
            || DB::table('pos_cash_movements')->where('currency', $code)->exists());

        // IMP-02: an imported item sold, or customer sold to, keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('item', 'sale', fn (array $ids) => DB::table('pos_sale_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());
        $usage->register('party', 'sale', fn (array $ids) => DB::table('pos_sales')->whereIn('customer_id', $ids)->distinct()->pluck('customer_id')->all());

        // NFR-04: what the till pulls from the POS module (core's sync API).
        $sources = $this->app->make(SyncSources::class);
        $sources->register(new NumberRangeSource);
        $sources->register(new OpenShiftSource);
        $sources->register(new PosLayoutSource);

        // LAY-05: the sell screen's layout, versioned configuration found only
        // while the module is active (RBAC-08).
        $this->app->make(ConfigKinds::class)->register(PosLayout::kind());
        // LAY-04: the POS lists that may have saved views (the web list ids).
        $lists = $this->app->make(ListViewCatalogue::class);
        $lists->register('pos-sales');
        $lists->register('pos-shifts');

        // TPL-01, TPL-05: the receipt templates that apply at the till's branch.
        $sources->register(new TemplateSource);

        // TPL-01: receipts are printed from document templates (core), with this module's data.
        $documents = $this->app->make(DataSources::class);
        $documents->register(new ReceiptSource('pos.receipt'));
        $documents->register(new ReceiptSource('pos.refund_receipt'));

        // LAY-01: dashboard widgets of today's sales and sales per day
        // (found only while the module is active, RBAC-08).
        $dashboards = $this->app->make(DashboardSources::class);
        $dashboards->register(new SalesToday);
        $dashboards->register(new SalesByDay);

        // NUM-02: a lost device's ranges stop when it is unpaired.
        Event::listen(DeviceUnpaired::class, RetireDeviceRanges::class);

        // POS-10: sales, refunds and voids go to the core fiscal queue,
        // built from this module's tables (PosFiscalSource).
        $this->app->make(FiscalSources::class)->register(PosFiscalSource::KEY, PosFiscalSource::class);
        foreach ([SaleCompleted::class, SaleRefunded::class, SaleVoided::class] as $event) {
            Event::listen($event, [QueueFiscalDocument::class, 'handle']);
        }

        // Concept note 7.1: mobile money codes recorded at the till become
        // payment intents to verify; mobile money refunds are paid back.
        Event::listen(SaleCompleted::class, [LinkMobileMoneyPayments::class, 'handle']);
        Event::listen(SaleRefunded::class, [LinkMobileMoneyPayments::class, 'handle']);
        Event::listen(PaymentIntentSettled::class, [ApplyPaymentSettlement::class, 'handle']);

        // ONB-07: the checklist ends with the first sale. ONB-06: sample
        // items and customers a real sale used cannot be removed.
        $this->app->make(Checklist::class)->register('first_sale', self::MODULE, fn () => DB::table('pos_sales')->exists(), 50, ends: true);
        $this->app->make(SampleDependents::class)->register(fn (array $records) => [
            ...DB::table('pos_sale_lines')->whereIn('item_id', $records['item'] ?? [])->distinct()->pluck('sale_id')
                ->map(fn ($id) => ['type' => 'item', 'id' => (string) $id, 'used_by' => 'pos_sale'])->all(),
            ...DB::table('pos_sales')->whereIn('customer_id', $records['party'] ?? [])->pluck('id')
                ->map(fn ($id) => ['type' => 'party', 'id' => (string) $id, 'used_by' => 'pos_sale'])->all(),
            ...DB::table('pos_sales')->whereIn('price_list_id', $records['price_list'] ?? [])->pluck('id')
                ->map(fn ($id) => ['type' => 'price_list', 'id' => (string) $id, 'used_by' => 'pos_sale'])->all(),
            // LAY-05: a live (draft or published) sell-screen layout pinning a sample item or category.
            ...self::layoutsNaming([...($records['item'] ?? []), ...($records['item_category'] ?? [])]),
        ]);

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
