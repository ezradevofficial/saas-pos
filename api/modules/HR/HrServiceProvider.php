<?php

namespace Modules\HR;

use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\CountryPacks\PackSchemas;
use App\Core\Currency\CurrencyUsage;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\Identity\Models\User;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\Limits\EmployeeCount;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\SubjectSource;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Search\RecordSearch;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\HR\Attendance\ClockAttempts;
use Modules\HR\Console\HrDailyCommand;
use Modules\HR\Console\RekeyDigestsCommand;
use Modules\HR\Employees\ActiveEmployees;
use Modules\HR\Employees\EmployeeEntity;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
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
use Modules\HR\Listeners\RefreshLeaveDays;
use Modules\HR\Listeners\SettleHrDocument;
use Modules\HR\Models\DisciplinaryCase;
use Modules\HR\Models\Employee;
use Modules\HR\Rosters\Schedules;
use Modules\HR\Rosters\ShiftSwapType;
use Modules\HR\Search\EmployeeSearcher;
use Modules\HR\Support\Access;
use Modules\HR\Support\HrPack;
use Modules\HR\Support\HrPackSchema;
use Modules\HR\Support\Numbers;
use Modules\HR\Support\PrivacyMasks;
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

    /** HR-24, MKT-04: the attendance connector's reads (roster, devices) and punch uploads, per caller and route. */
    public const CONNECTOR_READS_LIMITER = 'hr-connector-reads';

    public const CONNECTOR_PUNCHES_LIMITER = 'hr-connector-punches';

    /** RBAC-01: the module's permission catalogue (`hr.resource.action`). */
    public const PERMISSIONS = [
        // HR-02, HR-05: `terminate` also rehires; `link` links a user (self-service, AUTH-12).
        'employee' => ['view', 'create', 'edit', 'terminate', 'link'],
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
        // HR-24, MKT-04: `view` reads the devices (the biometric connector).
        'device' => ['view', 'manage'],
        'document' => ['view', 'manage'],
        'checklist' => ['view', 'manage'],
        'disciplinary' => ['view', 'manage'],
        'appraisal' => ['view', 'manage'],
    ];

    /**
     * Owner decision B: HR's employee records come with Payroll sold
     * alone; the rest of HR needs HR.
     */
    public const PAYROLL = 'payroll';

    public const CORE_RESOURCES = ['employee', 'identity', 'bank', 'pay', 'contract', 'position'];

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
        $this->mergeConfigFrom(__DIR__.'/config/hr.php', 'hr');
        // HR-32, LIM-01: the core counts active employees through HR.
        $this->app->singleton(EmployeeCount::class, ActiveEmployees::class);
        $this->app->singleton(HrPack::class);
        $this->app->scoped(Sensitive::class);
        $this->app->scoped(Schedules::class);
        // NFR-08: an employee is a data subject. Anonymisation clears contact,
        // medical, emergency and biometric data; the name, identifiers and
        // employment dates stay for statutory employment and tax records
        // (docs/privacy.md, retention conflicts).
        $this->callAfterResolving(PrivacySubjects::class, function (PrivacySubjects $subjects) {
            $subjects->register(new SubjectSource(
                subjectType: 'employee',
                table: 'hr_employees',
                match: 'id',
                primary: true,
                personal: [
                    'work_email' => 'null', 'work_phone' => 'null', 'personal_email' => 'null', 'personal_phone' => 'null',
                    'address' => 'null', 'emergency_contacts' => 'empty_array', 'medical_notes' => 'null', 'biometric_ref' => 'null',
                    'custom' => 'null',
                ],
                correctable: [
                    'first_name' => ['sometimes', 'string', 'min:1', 'max:100'],
                    'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                    'last_name' => ['sometimes', 'string', 'min:1', 'max:100'],
                    'personal_email' => ['sometimes', 'nullable', 'email', 'max:254'],
                    'personal_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                    'work_email' => ['sometimes', 'nullable', 'email', 'max:254'],
                    'work_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                ],
                display: ['first_name', 'last_name', 'number'],
            ));
            // Review H1: only rows about the employee (employee_id), never rows they
            // wrote about others (an appraisal they reviewed, someone else's swap).
            foreach (['hr_employee_identifiers', 'hr_employee_places', 'hr_attendance_days', 'hr_checklists', 'hr_contracts', 'hr_disciplinary_cases',
                'hr_documents', 'hr_leave_entries', 'hr_leave_requests', 'hr_pay_accounts', 'hr_punches', 'hr_roster_members', 'hr_roster_shifts',
                'hr_shift_swaps', 'hr_appraisals'] as $table) {
                $subjects->register(new SubjectSource(subjectType: 'employee', table: $table, match: 'employee_id', related: true));
            }
            // A user's own employee record (HR-01), masked like any other.
            $subjects->register(new SubjectSource(subjectType: 'user', table: 'hr_employees', match: 'user_id', related: true));
            // Review H2: sensitive groups the downloader may not see are masked; groups shown are audited.
            $masks = $this->app->make(PrivacyMasks::class);
            foreach (['hr_employees', 'hr_employee_identifiers', 'hr_pay_accounts', 'hr_contracts', 'hr_disciplinary_cases', 'hr_documents'] as $table) {
                $subjects->mask($table, $masks->forTable($table));
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);
        // LAY-01: the quick search finds this module's records (only while it is active).
        $this->app->make(RecordSearch::class)->register(new EmployeeSearcher);
        // ADM-04b: the HR pack release schema (ADR 022).
        HrPackSchema::register($this->app->make(PackSchemas::class));
        $this->app->make(ModuleRegistry::class)->provide(self::MODULE, self::PAYROLL, self::CORE_RESOURCES);

        // WF-01, WF-02, HR-14, HR-19: leave requests and shift swaps run through flows.
        $types = $this->app->make(DocumentTypeRegistry::class);
        $types->register(LeaveRequestType::class);
        $types->register(ShiftSwapType::class);
        Event::listen(WorkflowCompleted::class, SettleHrDocument::class);
        Event::listen(WorkflowCancelled::class, SettleHrDocument::class);
        // HR-25: leave days on the timesheets.
        Event::listen([LeaveApproved::class, LeaveCancelled::class], RefreshLeaveDays::class);

        // HR-23: lockouts of clock-in PINs alert HR.
        ClockAttempts::register($this->app->make(EventTypes::class));

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

            return $tenants->id() !== null && $this->app->make(ModuleRegistry::class)->anyActive([self::MODULE, self::PAYROLL]) ? [new EmployeeEntity] : [];
        });

        // IMP-01, HR-34: the employees importer; an imported employee with HR records keeps its import from being undone.
        $this->app->make(ImportRegistry::class)->register(new EmployeesImporter);
        $this->app->make(RecordUsage::class)->register(EmployeeEntity::KEY, 'hr_record', fn (array $ids) => array_values(array_unique([
            ...DB::table('hr_contracts')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
            ...DB::table('hr_leave_requests')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
            ...DB::table('hr_punches')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
            ...DB::table('hr_roster_shifts')->whereIn('employee_id', $ids)->pluck('employee_id')->all(),
        ])));

        // MD-07, RBAC-05: record history of employees (field rules applied) and disciplinary cases
        // (only for holders of the group, so their category and status stay hidden from others).
        $history = $this->app->make(HistoryTypes::class);
        $history->register('hr_employee', Employee::class, Sensitive::RESOURCE, fn (User $user, Employee $employee) => $this->app->make(Access::class)->can($user, 'hr.employee.view', $employee));
        $history->register('hr_disciplinary_case', DisciplinaryCase::class, Sensitive::RESOURCE, function (User $user, DisciplinaryCase $case) {
            $employee = Employee::query()->find($case->employee_id);

            return $employee !== null && $this->app->make(Sensitive::class)->sees($user, $employee, Sensitive::DISCIPLINARY);
        });

        // HR-23, NFR-04: who clocks in on a till, with their clock-in PIN material.
        $this->app->make(SyncSources::class)->register($this->app->make(ClockStaffSource::class));

        // CUR-01: once pay is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('hr_contracts')->where('currency', $code)->exists() || DB::table('hr_pay_accounts')->where('currency', $code)->exists());

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([HrDailyCommand::class, RekeyDigestsCommand::class]);
        }

        // HR-24, MKT-04: the connector routes' own limits, besides the public API's per key and tenant (API-03).
        $by = fn (Request $request, string $name) => $name.'|'.($request->user()?->getAuthIdentifier() ?? 'ip|'.$request->ip()).'|'.($request->route()?->uri() ?? '');
        RateLimiter::for(self::CONNECTOR_READS_LIMITER, fn (Request $request) => Limit::perMinute((int) config('hr.connector.reads_per_minute', 120))->by($by($request, self::CONNECTOR_READS_LIMITER)));
        RateLimiter::for(self::CONNECTOR_PUNCHES_LIMITER, fn (Request $request) => Limit::perMinute((int) config('hr.connector.punches_per_minute', 120))->by($by($request, self::CONNECTOR_PUNCHES_LIMITER)));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
