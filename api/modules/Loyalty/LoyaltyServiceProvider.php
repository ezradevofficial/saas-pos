<?php

namespace Modules\Loyalty;

use App\Core\Currency\CurrencyUsage;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\Prices\DiscountSources;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Payments\Tenders\TenderProviders;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Loyalty\Console\ExpirePointsCommand;
use Modules\Loyalty\Console\ReprocessSalesCommand;
use Modules\Loyalty\Documents\StatementSource;
use Modules\Loyalty\Notifications\LoyaltyNotices;
use Modules\Loyalty\Pos\HandlePosEvents;
use Modules\Loyalty\Pos\PosSales;
use Modules\Loyalty\Promotions\Promotions;
use Modules\Loyalty\Sync\GiftCardSource;
use Modules\Loyalty\Sync\MemberSource;
use Modules\Loyalty\Sync\PromotionSource;
use Modules\Loyalty\Sync\SettingsSource;
use Modules\Loyalty\Tenders\LoyaltyTenders;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * The Loyalty, promotions and credit module (docs/modules/loyalty.md).
 * Registered for every tenant, as Laravel boots once for all of them; what
 * a tenant reaches follows its subscription (RBAC-08): the routes carry
 * `module:loyalty`, the `loyalty.*` permissions grant nothing while it is
 * off, sync entities, tenders and promotion discounts are not served, and
 * POS events are ignored unless both modules are active.
 */
class LoyaltyServiceProvider extends ServiceProvider
{
    public const MODULE = 'loyalty';

    /** LOY-23: gift card balance checks, per user or device, so codes cannot be guessed. */
    public const LOOKUP_LIMITER = 'loyalty-lookup';

    /** RBAC-01: the module's permission catalogue (`loyalty.resource.action`). */
    public const PERMISSIONS = [
        'programme' => ['view', 'edit'],
        'promotion' => ['view', 'create', 'edit', 'archive'],
        'points' => ['view', 'adjust'],
        'credit' => ['view', 'manage', 'receive', 'charge', 'adjust', 'sell'],
        'gift_card' => ['view', 'issue', 'sell', 'block'],
        // H2: the review list of flagged POS sales.
        'sale' => ['view'],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/loyalty.php', 'loyalty');
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        RateLimiter::for(self::LOOKUP_LIMITER, fn (Request $request) => Limit::perMinute(30)->by(self::LOOKUP_LIMITER.'|'.($request->user()?->getKey() ?? $request->ip())));
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NUM-01, LOY-17: statements are numbered per company.
        $this->app->make(DocumentNumberTypes::class)->register(new DocumentNumberType(
            'loyalty.statement', self::MODULE, 'ST-{YYYY}-{00001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'loyalty.numbering.statement',
        ));

        // LOY-27: the tenders this module keeps the value of, and the promotions' discounts.
        $tenders = $this->app->make(TenderProviders::class);
        foreach (['points', 'voucher', 'credit'] as $type) {
            $tenders->register(new LoyaltyTenders($type));
        }
        // H2: only what the promotions engine confirms for a line is vouched for.
        $this->app->make(DiscountSources::class)->register(PosSales::PROMOTION_SOURCE, self::MODULE, fn (array $sale) => $this->app->make(Promotions::class)->verify($sale));

        // NFR-04: what the till pulls (served only while the module is active).
        $sources = $this->app->make(SyncSources::class);
        $sources->register(new SettingsSource);
        $sources->register(new PromotionSource);
        $sources->register(new MemberSource);
        $sources->register(new GiftCardSource);
        // Staff rows carry `loyalty.*` permissions (selling on credit at the till).
        $sources->tillPermissionPrefix('loyalty.');

        // TPL-01, LOY-17: the live source of customer statements.
        $this->app->make(DataSources::class)->register(new StatementSource);

        // NOT-02: the module's notifications.
        LoyaltyNotices::register($this->app->make(EventTypes::class));

        // LAY-04: lists that may have saved views.
        $lists = $this->app->make(ListViewCatalogue::class);
        foreach (['loyalty-promotions', 'loyalty-gift-cards', 'loyalty-accounts', 'loyalty-ageing', 'loyalty-sales'] as $list) {
            $lists->register($list);
        }

        // CUR-01: amounts stored in a currency lock its decimals.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('loyalty_credit_entries')->where('currency', $code)->exists()
            || DB::table('loyalty_gift_card_entries')->where('currency', $code)->exists()
            || DB::table('loyalty_programmes')->where('currency', $code)->exists());

        // IMP-02: an imported customer with a loyalty account keeps its import from being undone.
        $this->app->make(RecordUsage::class)->register('party', 'loyalty_account', fn (array $ids) => DB::table('loyalty_accounts')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());

        // LOY-03..LOY-05, LOY-26: the POS events, when the POS module exists (acted on only with both active).
        if (class_exists(SaleCompleted::class)) {
            Event::listen(SaleCompleted::class, [HandlePosEvents::class, 'completed']);
            Event::listen(SaleVoided::class, [HandlePosEvents::class, 'voided']);
            Event::listen(SaleRefunded::class, [HandlePosEvents::class, 'refunded']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ExpirePointsCommand::class, ReprocessSalesCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
