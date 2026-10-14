<?php

namespace Modules\HR;

use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\Currency\CurrencyUsage;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\Limits\EmployeeCount;
use App\Core\MasterData\RecordUsage;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\HR\Console\HrDailyCommand;
use Modules\HR\Employees\ActiveEmployees;
use Modules\HR\Employees\EmployeeEntity;
use Modules\HR\Http\Lists\AppraisalList;
use Modules\HR\Http\Lists\DisciplinaryList;
use Modules\HR\Http\Lists\EmployeeList;
use Modules\HR\Http\Lists\LeaveRequestList;
use Modules\HR\Http\Lists\PunchList;
use Modules\HR\Http\Lists\RosterShiftList;
use Modules\HR\Http\Lists\TimesheetList;
use Modules\HR\Imports\EmployeesImporter;
use Modules\HR\Leave\LeaveRequestType;
use Modules\HR\Leave\LineManagerResolver;
use Modules\HR\Listeners\SettleHrDocument;
use Modules\HR\Rosters\Schedules;
use Modules\HR\Rosters\ShiftSwapType;
use Modules\HR\Support\HrPack;
use Modules\HR\Support\Numbers;
use Modules\HR\Support\Sensitive;
use Modules\HR\Sync\ClockStaffSource;

/**
 * The HR module (docs/modules/hr.md). Registered for every tenant, as
 * Laravel boots once for all of them; what a tenant reaches follows its
 * subscription (RBAC-08): the routes carry `module:hr` (403
 * `module_inactive` otherwise), the `hr.*` permissions grant nothing and
 * system roles get them only once the module is active, and its document
 * types, number types, sync entity and custom field entity are found only
 * then. It works with only the core; Payroll consumes its events (HR-33).
 */
class HrServiceProvider extends ServiceProvider
{
    public const MODULE = 'hr';

    /** RBAC-01: the module's permission catalogue (`hr.resource.action`). */
    public const PERMISSIONS = [
        // HR-02, HR-05: `terminate` also rehires.
        'employee' => ['view', 'create', 'edit', 'terminate'],
        // HR-09: the sensitive groups.
        'identity' => ['view', 'edit'],
        'bank' => ['view', 'edit'],
        'pay' => ['view', 'edit'],
        'medical' => ['view', 'edit'],
        'contract' => ['view', 'manage'],
        'position' => ['view', 'manage'],
        // HR-12 to HR-15: `approve` acts on the leave flow (WF-08).
        'leave' => ['view', 'request', 'approve', 'manage'],
        'roster' => ['view', 'manage'],
        // HR-19: acts on the swap flow.
        'swap' => ['approve'],
        'attendance' => ['view', 'manage', 'close'],
        'device' => ['manage'],
        'document' => ['view', 'manage'],
        'checklist' => ['view', 'manage'],
        'disciplinary' => ['view', 'manage'],
        'appraisal' => ['view', 'manage'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids => their definition). */
    public const LISTS = [
        'hr-employees' => EmployeeList::class,
        'hr-leave-requests' => LeaveRequestList::class,
        'hr-roster-shifts' => RosterShiftList::class,
        'hr-punches' => PunchList::class,
        'hr-timesheets' => TimesheetList::class,
        'hr-disciplinary-cases' => DisciplinaryList::class,
        'hr-appraisals' => AppraisalList::class,
    ];

    public function register(): void
    {
        // HR-32, LIM-01: the core counts active employees through HR.
        $this->app->singleton(EmployeeCount::class, ActiveEmployees::class);
        $this->app->singleton(HrPack::class);
        $this->app->scoped(Sensitive::class);
        $this->app->scoped(Schedules::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02, HR-14, HR-19: leave requests and shift swaps run through flows.
        $types = $this->app->make(DocumentTypeRegistry::class);
        $types->register(LeaveRequestType::class);
        $types->register(ShiftSwapType::class);
        Event::listen(WorkflowCompleted::class, SettleHrDocument::class);
        Event::listen(WorkflowCancelled::class, SettleHrDocument::class);

        // APR-02: the requester's line manager.
        $this->app->make(ApproverResolvers::class)->register(LineManagerResolver::class);

        // NUM-01, HR-36.
        $numbers = $this->app->make(DocumentNumberTypes::class);

        foreach (Numbers::types() as $type) {
            $numbers->register($type);
        }

        // LAY-04: lists with saved views (field rules applied to the employee list's views).
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $key => $definition) {
            $lists->register($key, $definition);
        }

        // CF-01: employees carry custom fields while the tenant has HR (RBAC-08).
        $this->app->make(CustomFieldEntities::class)->registerSource(function () {
            $tenants = $this->app->make(TenantContext::class);

            return $tenants->id() !== null && $this->app->make(ModuleRegistry::class)->isActive(self::MODULE) ? [new EmployeeEntity] : [];
        });

        // IMP-01, HR-34: the employees importer; an imported employee with HR records keeps its import from being undone.
        $this->app->make(ImportRegistry::class)->register(new EmployeesImporter);
        $this->app->make(RecordUsage::class)->register(EmployeeEntity::KEY, 'hr_record', fn (array $ids) => array_values(array_unique([
            ...DB::table('hr_contracts')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
            ...DB::table('hr_leave_requests')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
            ...DB::table('hr_punches')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
            ...DB::table('hr_roster_shifts')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
        ])));

        // HR-23, NFR-04: who clocks in on a till, with their clock-in PIN material.
        $this->app->make(SyncSources::class)->register($this->app->make(ClockStaffSource::class));

        // CUR-01: once pay is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('hr_contracts')->where('currency', $code)->exists() || DB::table('hr_pay_accounts')->where('currency', $code)->exists());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([HrDailyCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
