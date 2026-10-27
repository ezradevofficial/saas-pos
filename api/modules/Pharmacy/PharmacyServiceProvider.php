<?php

namespace Modules\Pharmacy;

use App\Core\Currency\CurrencyUsage;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\Retention;
use App\Core\Privacy\SubjectSource;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Events\TransferReceived;
use Modules\Pharmacy\Alerts\NotifyReviewers;
use Modules\Pharmacy\Alerts\PharmacyNotices;
use Modules\Pharmacy\Console\ExpiryAlertsCommand;
use Modules\Pharmacy\Events\DispenseFlagged;
use Modules\Pharmacy\Events\RecallIssued;
use Modules\Pharmacy\Prescriptions\Prescriptions;
use Modules\Pharmacy\Sales\HandlePosEvents;
use Modules\Pharmacy\Stock\MoveLotsForTransfer;
use Modules\Pharmacy\Stock\RecordGoodsReceived;
use Modules\Pharmacy\Support\CompanyDays;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Sync\ItemProfileSource;
use Modules\Pharmacy\Sync\LotSource;
use Modules\Pharmacy\Sync\SettingsSource;
use Modules\Pharmacy\WriteOffs\SettleWriteOff;
use Modules\Pharmacy\WriteOffs\WriteOffs;
use Modules\Pharmacy\WriteOffs\WriteOffType;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * The Pharmacy and agrovet module (docs/modules/pharmacy.md). Registered for
 * every tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:pharmacy`
 * (403 `module_inactive` otherwise), the `pharmacy.*` permissions grant
 * nothing while it is off, its document type, number type, sync entities and
 * nightly job are found only then, and its listeners check the module before
 * acting for a tenant.
 */
class PharmacyServiceProvider extends ServiceProvider
{
    public const MODULE = 'pharmacy';

    /** RBAC-01: the module's permission catalogue (`pharmacy.resource.action`). */
    public const PERMISSIONS = [
        'settings' => ['view', 'edit'],
        // PHA-03: item profiles (class, lot tracking, usage notes).
        'item' => ['view', 'configure'],
        // PHA-04 to PHA-13: `view` also receives expiry alerts (PHA-10).
        'lot' => ['view', 'receive', 'quarantine', 'transfer'],
        // PHA-12: `approve` acts on the write-off flow (WF-08).
        'writeoff' => ['view', 'create', 'approve', 'cancel'],
        // `customers`: the names and phones of who bought the batch (personal data).
        'recall' => ['view', 'customers', 'issue', 'lift'],
        // PHA-14, PHA-15, PHA-28: personal data; `image` downloads the image.
        'prescription' => ['view', 'create', 'cancel', 'image'],
        // PHA-17, PHA-26: a pharmacist authorises a restricted dispense (named so that no `*.approve` pattern grants it by accident); reviewers close flagged ones.
        'dispense' => ['authorise', 'review'],
        'register' => ['view', 'print', 'adjust'],
        'report' => ['view'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids). */
    public const LISTS = ['pharmacy-lots', 'pharmacy-expiry', 'pharmacy-slow-movers', 'pharmacy-register', 'pharmacy-prescriptions', 'pharmacy-dispenses', 'pharmacy-write-offs'];

    public function register(): void
    {
        $this->app->scoped(Places::class);
        $this->app->scoped(CompanyDays::class);

        // NFR-08, PHA-28: patients and customers named in the module's records. A patient is the customer a prescription is for
        // (`patient_party_id`) or the customer of the sale it was taken down for; the free-text name is erased with them.
        $this->callAfterResolving(PrivacySubjects::class, function (PrivacySubjects $subjects) {
            $patientOf = fn (string $column) => function (QueryBuilder $query, string $subjectId) use ($column) {
                $query->whereIn($column, fn (QueryBuilder $q) => $q->from('pharmacy_prescriptions')->select('id')->where(fn (QueryBuilder $w) => $w
                    ->where('patient_party_id', $subjectId)
                    ->orWhereIn('id', fn (QueryBuilder $d) => $d->from('pharmacy_dispenses')->select('prescription_id')->where('customer_id', $subjectId)->whereNotNull('prescription_id'))));
            };

            // Prescriptions are kept (TEN-06; the period is to confirm) but stop naming the patient; the image is deleted.
            $subjects->register(new SubjectSource(
                subjectType: 'customer',
                table: 'pharmacy_prescriptions',
                match: 'patient_party_id',
                personal: ['patient_name' => 'label', 'patient_phone' => 'null', 'patient_birth_date' => 'null', 'notes' => 'null'],
                after: fn (string $subjectId) => Prescriptions::eraseImagesOf($subjectId, $patientOf('id')),
                related: true,
                matcher: $patientOf('id'),
            ));
            // The register is append-only and a regulator may require it: its rows are listed and kept (retention to confirm).
            $subjects->register(new SubjectSource(subjectType: 'customer', table: 'pharmacy_register_entries', match: 'prescription_id', personal: ['patient_name' => 'label'], related: true, matcher: $patientOf('prescription_id')));
            $subjects->retain('customer', function (string $subjectId) use ($patientOf) {
                $rows = DB::connection(TenantContext::CONNECTION)->table('pharmacy_register_entries');
                $patientOf('prescription_id')($rows, $subjectId);

                return $rows->exists() ? new Retention(keep: ['pharmacy_register_entries' => ['patient_name' => 'keep']], reason: 'controlled_drug_register') : null;
            });

            foreach (['pharmacy_dispenses', 'pharmacy_lot_movements'] as $table) {
                $subjects->register(new SubjectSource(subjectType: 'customer', table: $table, match: 'customer_id', related: true));
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02: write-offs run through a flow.
        $this->app->make(DocumentTypeRegistry::class)->register(WriteOffType::class);
        Event::listen(WorkflowCompleted::class, SettleWriteOff::class);
        Event::listen(WorkflowCancelled::class, SettleWriteOff::class);

        // NUM-01, PHA-12: write-offs are numbered per branch.
        $this->app->make(DocumentNumberTypes::class)->register(WriteOffs::numberType());

        // PHA-06: goods received by Procurement enter the lots of tracked items (Inventory's contract).
        Event::listen(GoodsReceived::class, [RecordGoodsReceived::class, 'handle']);
        // PHA-13: Inventory's own transfers move lots first-expiry-first-out once received.
        Event::listen(TransferReceived::class, [MoveLotsForTransfer::class, 'handle']);

        // PHA-16, PHA-26, PHA-27: the POS events, when the POS module exists (acted on only with both active).
        if (class_exists(SaleCompleted::class)) {
            Event::listen(SaleCompleted::class, [HandlePosEvents::class, 'completed']);
            Event::listen(SaleVoided::class, [HandlePosEvents::class, 'voided']);
            Event::listen(SaleRefunded::class, [HandlePosEvents::class, 'refunded']);
        }

        // PHA-22, PHA-26, NOT-02: the module's notifications.
        PharmacyNotices::register($this->app->make(EventTypes::class));
        Event::listen(RecallIssued::class, [NotifyReviewers::class, 'recall']);
        Event::listen(DispenseFlagged::class, [NotifyReviewers::class, 'flagged']);

        // PHA-25, NFR-04: what the till pulls (served only while the module is active).
        $sources = $this->app->make(SyncSources::class);
        $sources->register(new SettingsSource);
        $sources->register(new ItemProfileSource);
        $sources->register(new LotSource);
        // Staff rows carry `pharmacy.` permissions (a pharmacist approves a restricted dispense at the till).
        $sources->tillPermissionPrefix('pharmacy.');

        // LAY-04: lists with saved views.
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $list) {
            $lists->register($list);
        }

        // CUR-01: amounts stored in a currency lock its decimals.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('pharmacy_receipt_lines')->where('currency', $code)->exists());

        // IMP-02: an imported item with lots keeps its import from being undone.
        $this->app->make(RecordUsage::class)->register('item', 'pharmacy_lot', fn (array $ids) => DB::table('pharmacy_lots')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        if ($this->app->runningInConsole()) {
            $this->commands([ExpiryAlertsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // PHA-10: quarantine expired stock and tell pharmacists what is near expiry or expired.
            $schedule->command('pharmacy:expiry-alerts')->dailyAt('03:10')->withoutOverlapping()->onOneServer();
        });

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
