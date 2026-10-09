<?php

namespace Modules\POS;

use App\Core\Configuration\ConfigKinds;
use App\Core\Currency\CurrencyUsage;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Fiscal\FiscalSources;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Events\DeviceUnpaired;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\POS\Dashboards\SalesByDay;
use Modules\POS\Dashboards\SalesToday;
use Modules\POS\Documents\ReceiptSource;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
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

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/pos.php', 'pos');
        // AUTH-07, AUTH-08: core's PIN verifier once it is installed, else nothing is provable (held).
        $this->app->bindIf(OverrideVerifier::class, CoreOverrides::class);
        $this->app->scoped(Sellability::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NUM-01, NUM-02: receipts and refund receipts, drawn from device ranges.
        $types = $this->app->make(DocumentNumberTypes::class);
        $types->register(new DocumentNumberType('pos.receipt', self::MODULE, 'R-{LOCATION}-{000001}', NumberFormat::RESET_NEVER, ['BRANCH', 'LOCATION', 'DEVICE'], ranged: true, langKey: 'pos.numbering.receipt'));
        $types->register(new DocumentNumberType('pos.refund', self::MODULE, 'RF-{LOCATION}-{000001}', NumberFormat::RESET_NEVER, ['BRANCH', 'LOCATION', 'DEVICE'], ranged: true, langKey: 'pos.numbering.refund'));

        // CUR-01: once a sale, payment or float is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('pos_sales')->where('currency', $code)->exists()
            || DB::table('pos_sale_payments')->where('currency', $code)->exists()
            || DB::table('pos_shift_balances')->where('currency', $code)->exists()
            || DB::table('pos_cash_movements')->where('currency', $code)->exists());

        // NFR-04: what the till pulls from the POS module (core's sync API).
        $sources = $this->app->make(SyncSources::class);
        $sources->register(new NumberRangeSource);
        $sources->register(new OpenShiftSource);
        $sources->register(new PosLayoutSource);

        // LAY-05: the sell screen's layout, versioned configuration found only
        // while the module is active (RBAC-08).
        $this->app->make(ConfigKinds::class)->register(PosLayout::kind());

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

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
