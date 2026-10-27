<?php

namespace Modules\Restaurant;

use App\Core\Currency\CurrencyUsage;
use App\Core\MasterData\RecordUsage;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleVoided;
use Modules\Restaurant\Listeners\HandleSales;
use Modules\Restaurant\Sync\Sources\ModifierSource;
use Modules\Restaurant\Sync\Sources\OrderSource;
use Modules\Restaurant\Sync\Sources\SettingsSource;
use Modules\Restaurant\Sync\Sources\StationSource;
use Modules\Restaurant\Sync\Sources\TableSource;

/**
 * The Restaurant module (docs/modules/restaurant.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:restaurant`
 * (403 `module_inactive` otherwise), the `restaurant.*` permissions grant
 * nothing and system roles get them only once the module is active, its
 * sync sources are served only then and its listener does nothing without
 * it. It is not sold on its own: it is switched on with the `restaurant` and
 * `bar` business types (presets.php `features`, owner to confirm) and works
 * with only the core and POS: orders become normal POS sales, so
 * Manufacturing's recipes and Inventory's stock follow by themselves.
 */
class RestaurantServiceProvider extends ServiceProvider
{
    public const MODULE = 'restaurant';

    /** RBAC-01: the module's permission catalogue (`restaurant.resource.action`). */
    public const PERMISSIONS = [
        // REST-02: areas and tables.
        'floor' => ['view', 'manage'],
        // REST-08: stations and routing.
        'station' => ['view', 'manage'],
        // REST-07: modifier groups.
        'modifier' => ['view', 'manage'],
        // REST-03 to REST-06, REST-10, REST-14, REST-15.
        'order' => ['view', 'create', 'edit', 'send', 'transfer', 'merge', 'close'],
        // REST-09, REST-12: the kitchen display and ticket status; `serve` is only the move to served (a waiter's).
        'ticket' => ['view', 'update', 'serve'],
        // REST-13: a company's service charge and tip items.
        'settings' => ['edit'],
        // REST-18.
        'report' => ['view'],
    ];

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NFR-04: what the till pulls from the Restaurant module (core's sync API).
        $sources = $this->app->make(SyncSources::class);

        foreach ([new TableSource, new StationSource, new ModifierSource, new SettingsSource, new OrderSource] as $source) {
            $sources->register($source);
        }

        // REST-16: sales that paid an order settle it; a voided one takes the settlement back.
        Event::listen(SaleCompleted::class, [HandleSales::class, 'completed']);
        Event::listen(SaleVoided::class, [HandleSales::class, 'voided']);

        // CUR-01: once an order, settlement or modifier is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('rest_orders')->where('currency', $code)->exists()
            || DB::table('rest_settlements')->where('currency', $code)->exists()
            || DB::table('rest_modifier_groups')->where('currency', $code)->exists());

        // IMP-02: an imported item on an order keeps its import from being undone.
        $this->app->make(RecordUsage::class)->register('item', 'restaurant_order', fn (array $ids) => DB::table('rest_order_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
