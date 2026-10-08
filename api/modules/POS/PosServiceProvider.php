<?php

namespace Modules\POS;

use App\Core\Currency\CurrencyUsage;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Tenancy\Events\DeviceUnpaired;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\POS\Listeners\RetireDeviceRanges;
use Modules\POS\Sync\CoreOverrides;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Sync\Sellability;
use Modules\POS\Sync\UnverifiedOverrides;

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
        'sale' => ['view', 'create', 'print', 'void', 'refund', 'review'],
        'shift' => ['view', 'open', 'close', 'manage'],
        'cash' => ['move'],
        'price' => ['override'],
        'discount' => ['give'],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/pos.php', 'pos');
        // AUTH-07, AUTH-08: core's PIN verifier once it is installed, else nothing is provable (held).
        $this->app->bindIf(OverrideVerifier::class, class_exists('App\\Core\\Identity\\Pin\\OverrideVerifier') ? CoreOverrides::class : UnverifiedOverrides::class);
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

        // NUM-02: a lost device's ranges stop when it is unpaired.
        Event::listen(DeviceUnpaired::class, RetireDeviceRanges::class);

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
