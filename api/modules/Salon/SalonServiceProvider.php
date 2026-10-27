<?php

namespace Modules\Salon;

use App\Core\Currency\CurrencyUsage;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Tenders\TenderProviders;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\SubjectSource;
use App\Core\Rbac\Events\ModuleActivated;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Commissions\Batches;
use Modules\Salon\Console\PurgeGuestsCommand;
use Modules\Salon\Console\RemindCommand;
use Modules\Salon\Listeners\HandleSales;
use Modules\Salon\Payments\SettleDeposit;
use Modules\Salon\Pos\CreditTender;
use Modules\Salon\Reminders\Notices;
use Modules\Salon\Support\Catalogue;
use Modules\Salon\Sync\Sources\PackagesSource;
use Modules\Salon\Sync\Sources\QueueSource;
use Modules\Salon\Sync\Sources\ScheduleSource;
use Modules\Salon\Sync\Sources\ServicesSource;
use Modules\Salon\Sync\Sources\SettingsSource;
use Modules\Salon\Sync\Sources\StaffSource;

/**
 * The Salon and services module (docs/modules/salon.md). Registered for every tenant, as Laravel boots once for all of
 * them; what a tenant reaches follows its subscription (RBAC-08): the routes carry `module:salon` (403 `module_inactive`
 * otherwise), the `salon.*` permissions grant nothing and system roles get them only once the module is active, its sync
 * sources and tender are served only then and its listeners and jobs do nothing without it. It is sold as a catalogue
 * module (per outlet, requires POS) and works with the core and POS alone: a visit is a normal POS sale; Inventory, HR,
 * Payroll, Loyalty, CRM and E-commerce switch on their integrations when both sides are subscribed. The public booking
 * page is the one route without a token (SAL-15).
 */
class SalonServiceProvider extends ServiceProvider
{
    public const MODULE = 'salon';

    public const BOOKING_LIMITER = 'salon-booking';

    public const BOOKING_WRITE_LIMITER = 'salon-booking-write';

    /** RBAC-01: the module's permission catalogue (`salon.resource.action`). */
    public const PERMISSIONS = [
        // SAL-10, SAL-11, SAL-14.
        'settings' => ['view', 'edit'],
        // SAL-02, SAL-18.
        'service' => ['view', 'manage'],
        // SAL-03, SAL-04.
        'staff' => ['view', 'manage'],
        // SAL-05.
        'resource' => ['view', 'manage'],
        // SAL-15.
        'online' => ['view', 'manage'],
        // SAL-07 to SAL-13: `view_own` reads the appointments one is staff on; `override` books outside hours.
        'appointment' => ['view', 'view_own', 'create', 'edit', 'cancel', 'serve', 'override'],
        // SAL-12.
        'queue' => ['view', 'manage'],
        // SAL-25: `notes` reads and writes allergies and formulas (named so that no `*.view` pattern grants it by accident).
        'client' => ['view', 'manage', 'notes'],
        // SAL-18: `adjust` corrects the sessions of a package, with a reason.
        'package' => ['view', 'adjust'],
        // SAL-11.
        'deposit' => ['take', 'refund'],
        // SAL-10.
        'fee' => ['waive'],
        // SAL-16 to SAL-18.
        'exception' => ['view', 'resolve'],
        // SAL-20 to SAL-24: `build` makes and submits a batch, `approve` decides it (never one's own), `export` sends it on.
        'commission' => ['view', 'view_own', 'rule', 'build', 'approve', 'export'],
        // SAL-27.
        'report' => ['view'],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/salon.php', 'salon');

        // NFR-08, SAL-26: where the salon holds personal data about a customer. A deletion clears the notes, names and
        // contacts; the visits, packages and sales stay for the counts and for law.
        $this->callAfterResolving(PrivacySubjects::class, function (PrivacySubjects $subjects) {
            $subjects->register(new SubjectSource(
                subjectType: 'customer',
                table: 'salon_clients',
                match: 'party_id',
                personal: ['preferences' => 'null', 'allergies' => 'null', 'formulas' => 'null', 'notes' => 'null'],
                fieldRules: 'salon_client',
                fieldColumns: ['allergies' => ['allergies'], 'formulas' => ['formulas'], 'notes' => ['notes']],
            ));
            $subjects->register(new SubjectSource(
                subjectType: 'customer',
                table: 'salon_appointments',
                match: 'client_id',
                personal: ['guest_name' => 'label', 'guest_phone' => 'null', 'guest_email' => 'null', 'notes' => 'null', 'service_notes' => 'null', 'cancel_reason' => 'null'],
                fieldRules: 'salon_client',
                fieldColumns: ['contact' => ['guest_phone', 'guest_email'], 'formulas' => ['service_notes'], 'notes' => ['notes']],
            ));
            $subjects->register(new SubjectSource(
                subjectType: 'customer',
                table: 'salon_queue',
                match: 'client_id',
                personal: ['guest_name' => 'label', 'guest_phone' => 'null'],
                fieldRules: 'salon_client',
                fieldColumns: ['contact' => ['guest_phone']],
            ));
            $subjects->register(new SubjectSource(subjectType: 'customer', table: 'salon_packages', match: 'customer_id', related: true));
        });
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // NUM-01, SAL-07, SAL-23: appointments are numbered per branch, commission batches per company.
        $types = $this->app->make(DocumentNumberTypes::class);
        $types->register(Bookings::numberType());
        $types->register(Batches::numberType());

        // NOT-02, SAL-14: the messages a client gets (transactional system messages to their contacts).
        Notices::register($this->app->make(EventTypes::class));

        // NFR-04: what the till pulls from the module (core's sync API); staff rows carry the `salon.` permissions.
        $sources = $this->app->make(SyncSources::class);

        foreach ([SettingsSource::class, StaffSource::class, ServicesSource::class, ScheduleSource::class, QueueSource::class, PackagesSource::class] as $source) {
            $sources->register($this->app->make($source));
        }

        $sources->tillPermissionPrefix('salon.');

        // SAL-17, SAL-18: the `salon_credit` tender (deposits and package sessions), checked when a till's sale arrives.
        $this->app->make(TenderProviders::class)->register($this->app->make(CreditTender::class));

        // SAL-16 to SAL-18, SAL-21: a sale, its void and its refund reach the appointment, credit, packages and commissions.
        if (class_exists(SaleCompleted::class)) {
            Event::listen(SaleCompleted::class, [HandleSales::class, 'completed']);
            Event::listen(SaleVoided::class, [HandleSales::class, 'voided']);
            Event::listen(SaleRefunded::class, [HandleSales::class, 'refunded']);
        }

        // SAL-11: M-Pesa pushes for deposits settle back to the appointment.
        Event::listen(PaymentIntentSettled::class, SettleDeposit::class);

        // ONB-02: a tenant from before the module gets its role templates and its prepaid-value payment method when it subscribes.
        Event::listen(ModuleActivated::class, function (ModuleActivated $event) {
            if ($event->module !== self::MODULE) {
                return;
            }

            $tenant = Tenant::query()->findOrFail($event->tenantId);
            $this->app->make(RoleTemplates::class)->ensure($tenant, ['stylist', 'salon_manager', 'salon_receptionist']);
            $this->app->make(TenantContext::class)->run($event->tenantId, function () {
                foreach (Company::query()->whereNull('archived_at')->get() as $company) {
                    $this->app->make(Catalogue::class)->creditMethod($company->id);
                }
            });
        });

        // CUR-01: once an amount is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('salon_appointments')->where('currency', $code)->exists()
            || DB::table('salon_packages')->where('currency', $code)->exists()
            || DB::table('salon_commission_entries')->where('currency', $code)->exists());

        // IMP-02: an item on the menu, sold as a package or used as a product keeps its import from being undone; so does a client's profile.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('item', 'salon_service', fn (array $ids) => DB::table('salon_services')->whereIn('item_id', $ids)->pluck('item_id')
            ->merge(DB::table('salon_package_types')->whereIn('item_id', $ids)->pluck('item_id'))->unique()->all());
        $usage->register('party', 'salon_client', fn (array $ids) => DB::table('salon_clients')->whereIn('party_id', $ids)->pluck('party_id')
            ->merge(DB::table('salon_appointments')->whereIn('client_id', $ids)->pluck('client_id'))->unique()->all());

        // SAL-15: the public page's limits, per address (reading) and, tighter, for writes (per address and per page).
        RateLimiter::for(self::BOOKING_LIMITER, fn (Request $request) => Limit::perMinute((int) config('salon.booking.per_ip_per_minute', 60))->by(self::BOOKING_LIMITER.'|'.$request->ip()));
        RateLimiter::for(self::BOOKING_WRITE_LIMITER, fn (Request $request) => [
            Limit::perMinute((int) config('salon.booking.writes_per_ip_per_minute', 5))->by(self::BOOKING_WRITE_LIMITER.'|ip|'.$request->ip()),
            Limit::perMinute((int) config('salon.booking.writes_per_page_per_minute', 30))->by(self::BOOKING_WRITE_LIMITER.'|code|'.strtolower((string) $request->route('code'))),
        ]);

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->commands([RemindCommand::class, PurgeGuestsCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // SAL-14, SAL-11: reminders, unpaid holds and expired packages, every ten minutes.
            $schedule->command('salon:remind')->everyTenMinutes()->withoutOverlapping()->onOneServer();
            // SAL-26: guests' contact details are cleared after the company's retention.
            $schedule->command('salon:purge-guests')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/public.php');
        }
    }
}
