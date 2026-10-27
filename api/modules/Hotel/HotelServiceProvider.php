<?php

namespace Modules\Hotel;

use App\Core\Currency\CurrencyUsage;
use App\Core\Fiscal\FiscalSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Tenders\TenderProviders;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\Retention;
use App\Core\Privacy\SubjectSource;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Hotel\Console\NightAuditCommand;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Folios\Settlement;
use Modules\Hotel\Folios\SettlePayment;
use Modules\Hotel\Integrations\HotelFiscalSource;
use Modules\Hotel\Pos\HandlePosEvents;
use Modules\Hotel\Pos\RoomChargeTender;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Sync\InHouseSource;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * The Hotel and lodging module (docs/modules/hotel.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches follows
 * its subscription (RBAC-08): the routes carry `module:hotel` (403
 * `module_inactive` otherwise), the `hotel.*` permissions grant nothing while it
 * is off, its number types, sync entity, tender and nightly job are found only
 * then, and its listeners check the module before acting for a tenant.
 */
class HotelServiceProvider extends ServiceProvider
{
    public const MODULE = 'hotel';

    /** RBAC-01: the module's permission catalogue (`hotel.resource.action`). */
    public const PERMISSIONS = [
        'settings' => ['view', 'edit'],
        // HOT-03 to HOT-05: `manage` also sets a room out of order.
        'room' => ['view', 'manage'],
        // HOT-06 to HOT-10: `override` types a rate different from the quote.
        'rate' => ['view', 'manage', 'override'],
        // HOT-16, HOT-38: personal data; `identity` shows ID numbers (named so that no `*.view` pattern grants it by accident).
        'guest' => ['view', 'manage', 'identity'],
        'reservation' => ['view', 'create', 'edit', 'cancel'],
        // HOT-17 to HOT-19, HOT-37: `checkin_early` checks a guest in before their booked arrival date.
        'stay' => ['checkin', 'checkin_early', 'checkout', 'move'],
        // HOT-19 to HOT-27: `settle_open` leaves a balance on account at check-out.
        'folio' => ['view', 'charge', 'payment', 'refund', 'void', 'transfer', 'settle_open'],
        // HOT-15, HOT-18: waive an early, late, cancellation or no-show fee.
        'fee' => ['waive'],
        // HOT-22: resolve room charges that could not be posted.
        'charge' => ['resolve'],
        'housekeeping' => ['view', 'assign', 'update'],
        'audit' => ['view', 'run'],
        'report' => ['view'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids). */
    public const LISTS = ['hotel-reservations', 'hotel-folios', 'hotel-tasks', 'hotel-charge-exceptions', 'hotel-guests'];

    public function register(): void
    {
        $this->app->scoped(Properties::class);

        // NFR-08, HOT-16: a guest is a customer; the identity document and contact details are personal data. Erasing the
        // customer clears them, but the guest register may be required by law (to confirm): it is kept while the guest has stays.
        $this->callAfterResolving(PrivacySubjects::class, function (PrivacySubjects $subjects) {
            $subjects->register(new SubjectSource(
                subjectType: 'customer',
                table: 'hotel_guests',
                match: 'party_id',
                personal: ['name' => 'label', 'phone' => 'null', 'email' => 'null', 'nationality' => 'null', 'id_type' => 'null', 'id_number' => 'null', 'date_of_birth' => 'null', 'address' => 'null', 'notes' => 'null'],
                related: true,
                fieldRules: 'hotel_guest',
                fieldColumns: ['identity_document' => ['id_number', 'id_type'], 'contact' => ['phone', 'email'], 'date_of_birth' => ['date_of_birth'], 'address' => ['address']],
            ));
            $subjects->retain('customer', function (string $subjectId) {
                $stays = DB::connection(TenantContext::CONNECTION)->table('hotel_stays')->whereIn('guest_id', fn ($q) => $q->select('id')->from('hotel_guests')->where('party_id', $subjectId));

                return $stays->exists()
                    ? new Retention(keep: ['hotel_guests' => ['name' => 'keep', 'nationality' => 'keep', 'id_type' => 'keep', 'id_number' => 'keep']], reason: 'guest_register')
                    : null;
            });
        });
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NUM-01, HOT-36: reservations, folios and invoices are numbered per branch.
        $types = $this->app->make(DocumentNumberTypes::class);
        $types->register(Reservations::numberType());
        $types->register(Folios::numberType());
        $types->register(Settlement::numberType());

        // HOT-27: a settled folio's invoice goes to the core fiscal queue.
        $this->app->make(FiscalSources::class)->register(HotelFiscalSource::KEY, HotelFiscalSource::class);

        // HOT-14, HOT-24: M-Pesa pushes for deposits and payments settle back to the folio.
        Event::listen(PaymentIntentSettled::class, SettlePayment::class);

        // HOT-22, LOY-27: the `room_charge` tender, and the POS events that post, reverse and credit it (acted on only with both modules active).
        $this->app->make(TenderProviders::class)->register($this->app->make(RoomChargeTender::class));

        if (class_exists(SaleCompleted::class)) {
            Event::listen(SaleCompleted::class, [HandlePosEvents::class, 'completed']);
            Event::listen(SaleVoided::class, [HandlePosEvents::class, 'voided']);
            Event::listen(SaleRefunded::class, [HandlePosEvents::class, 'refunded']);
        }

        // HOT-35, NFR-04: who is in house, for the till's offline room-charge tender. Staff rows carry `hotel.` permissions.
        $sources = $this->app->make(SyncSources::class);
        $sources->register(new InHouseSource);
        $sources->tillPermissionPrefix('hotel.');

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $list) {
            $lists->register($list);
        }

        // CUR-01: amounts stored in a currency lock its decimals.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('hotel_folio_lines')->where('currency', $code)->exists()
            || DB::table('hotel_rate_plans')->where('currency', $code)->exists());

        if ($this->app->runningInConsole()) {
            $this->commands([NightAuditCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // HOT-30: every hour, each property whose audit time has passed audits its business date (idempotent per branch and date).
            $schedule->command('hotel:night-audit')->hourlyAt(7)->withoutOverlapping()->onOneServer();
        });

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
