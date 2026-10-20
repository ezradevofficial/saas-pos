<?php

namespace Modules\Ecommerce;

use App\Core\Currency\CurrencyUsage;
use App\Core\Fiscal\FiscalSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\SubjectSource;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Ecommerce\Console\ExpireCommand;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Events\OrderReturned;
use Modules\Ecommerce\Http\Lists\OrderList;
use Modules\Ecommerce\Integrations\EcommerceFiscalSource;
use Modules\Ecommerce\Listeners\OrderIntegrations;
use Modules\Ecommerce\Listeners\SettleOrderPayment;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Orders\Placement;
use Modules\Ecommerce\Webhooks\EcommerceWebhookEvents;
use Modules\Ecommerce\WhatsApp\WhatsAppProviders;

/**
 * The E-commerce and delivery module (docs/modules/ecommerce.md).
 * Registered for every tenant, as Laravel boots once for all of them; what
 * a tenant reaches follows its subscription (RBAC-08): the back-office
 * routes carry `module:ecommerce` (403 `module_inactive` otherwise), the
 * storefront and the WhatsApp webhook check the module of the tenant they
 * resolve (404 otherwise); the `ecommerce.*` permissions grant nothing and
 * system roles get them only once the module is active. It works with only
 * the core; Inventory, Accounting, Loyalty and the fiscal queue are
 * reached through events and read contracts, each only while active.
 */
class EcommerceServiceProvider extends ServiceProvider
{
    public const MODULE = 'ecommerce';

    public const SHOP_LIMITER = 'ecommerce-shop';

    public const CHECKOUT_LIMITER = 'ecommerce-checkout';

    public const CODES_LIMITER = 'ecommerce-codes';

    public const WHATSAPP_LIMITER = 'ecommerce-whatsapp';

    /** ECOM-08: sign-in code checks, apart from code requests. */
    public const VERIFY_LIMITER = 'ecommerce-verify';

    /** RBAC-01: the module's permission catalogue (`ecommerce.resource.action`). */
    public const PERMISSIONS = [
        'store' => ['view', 'manage'],
        // ECOM-22: `create` takes phone and WhatsApp orders; `manage` moves orders through their statuses.
        'order' => ['view', 'create', 'manage', 'cancel', 'refund'],
        // ECOM-27, ECOM-28: `deliver` is the rider's own (own deliveries only).
        'delivery' => ['view', 'assign', 'deliver'],
        'rider' => ['manage'],
        'cashup' => ['view', 'manage'],
        'whatsapp' => ['view', 'reply'],
        'customer' => ['view'],
        'report' => ['view'],
    ];

    /** LAY-04: the module's lists that may have saved views. */
    public const LISTS = [
        'ecommerce-orders' => OrderList::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/ecommerce.php', 'ecommerce');
        // NFR-08: a storefront account is part of its customer's data;
        // anonymising the customer clears it and archives the account.
        $this->callAfterResolving(PrivacySubjects::class, fn (PrivacySubjects $subjects) => $subjects->register(new SubjectSource(
            subjectType: 'customer', table: 'ecom_customers', match: 'party_id',
            personal: ['name' => 'label', 'email' => 'null', 'phone' => 'null'],
        )));
        $this->app->singleton(WhatsAppProviders::class);
    }

    public function boot(): void
    {
        // HOOK-01: the module's public webhook events (sent only while it is active).
        EcommerceWebhookEvents::register($this->app->make(WebhookCatalogue::class));

        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NUM-01, ECOM-17: order numbers.
        $this->app->make(DocumentNumberTypes::class)->register(new DocumentNumberType(
            Placement::NUMBER_TYPE, self::MODULE, 'WEB-{BRANCH}-{YYYY}-{00001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'ecommerce.numbering.order',
        ));

        // ECOM-20, NOT-02: customer messages.
        OrderNotices::register($this->app->make(EventTypes::class));

        // ECOM-35: delivered orders and returns for the fiscal queue.
        $this->app->make(FiscalSources::class)->register(EcommerceFiscalSource::KEY, EcommerceFiscalSource::class);

        // ECOM-15: provider results for orders and refunds.
        Event::listen(PaymentIntentSettled::class, SettleOrderPayment::class);
        // ECOM-33, ECOM-35: after delivery and after a return of a delivered order.
        Event::listen(OrderEvent::class, OrderIntegrations::class);
        Event::listen(OrderReturned::class, OrderIntegrations::class);

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $key => $definition) {
            $lists->register($key, $definition);
        }

        // CUR-01: once an amount is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('ecom_orders')->where('currency', $code)->exists()
            || DB::table('ecom_stores')->where('currency', $code)->exists());

        // IMP-02: an imported item or customer used online keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('item', 'ecommerce_product', fn (array $ids) => DB::table('ecom_products')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());
        $usage->register('item', 'ecommerce_order', fn (array $ids) => DB::table('ecom_order_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());
        $usage->register('party', 'ecommerce_order', fn (array $ids) => DB::table('ecom_orders')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());

        // ECOM-07: the storefront's rate limits, per address (and per webhook token).
        $config = fn (string $key, int $default) => (int) config("ecommerce.{$key}", $default);
        RateLimiter::for(self::SHOP_LIMITER, fn (Request $request) => Limit::perMinute($config('shop.per_ip_per_minute', 120))->by(self::SHOP_LIMITER.'|'.$request->ip()));
        RateLimiter::for(self::CHECKOUT_LIMITER, fn (Request $request) => Limit::perMinute($config('shop.checkout_per_ip_per_minute', 10))->by(self::CHECKOUT_LIMITER.'|'.$request->ip()));
        RateLimiter::for(self::CODES_LIMITER, fn (Request $request) => Limit::perMinute($config('shop.codes_per_ip_per_minute', 10))->by(self::CODES_LIMITER.'|'.$request->ip()));
        RateLimiter::for(self::VERIFY_LIMITER, fn (Request $request) => Limit::perMinute($config('shop.verify_per_ip_per_minute', 10))->by(self::VERIFY_LIMITER.'|'.$request->ip()));
        // ECOM-24: per store token, and per calling address (unknown tokens included).
        RateLimiter::for(self::WHATSAPP_LIMITER, fn (Request $request) => [
            Limit::perMinute($config('whatsapp.per_minute', 120))->by(self::WHATSAPP_LIMITER.'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute($config('whatsapp.per_ip_per_minute', 300))->by(self::WHATSAPP_LIMITER.'|ip|'.$request->ip()),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // ECOM-14: unpaid orders whose reservation expired.
            $schedule->command('ecommerce:expire')->everyMinute()->withoutOverlapping()->onOneServer();
        });

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/shop.php');
        }
    }
}
