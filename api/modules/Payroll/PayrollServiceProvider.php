<?php

namespace Modules\Payroll;

use App\Core\DocumentTemplates\DataSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Support\EventReplays;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Events\ContractChanged;
use Modules\HR\Events\EmployeeHired;
use Modules\HR\Events\EmployeeTerminated;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\Payroll\Http\Lists\LoanList;
use Modules\Payroll\Http\Lists\PayEmployeeList;
use Modules\Payroll\Http\Lists\RunLineList;
use Modules\Payroll\Http\Lists\RunList;
use Modules\Payroll\Listeners\FollowHr;
use Modules\Payroll\Listeners\SettleRun;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Payments\GenericCsv;
use Modules\Payroll\Payments\MpesaB2cBulk;
use Modules\Payroll\Payments\PaymentFormats;
use Modules\Payroll\Payslips\PayslipSource;
use Modules\Payroll\Runs\PostingGroups;
use Modules\Payroll\Runs\RunType;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Support\Numbers;
use Modules\Payroll\Support\PayFields;

/**
 * The Payroll module (docs/modules/payroll.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the routes carry `module:payroll`,
 * the `payroll.*` permissions grant nothing and system roles get them only
 * once the module is active. Payroll sold alone brings HR's employee core
 * (HR registers that `provide`, owner decision B). It never reads HR's
 * tables: HR's events and read contracts feed its own copy.
 */
class PayrollServiceProvider extends ServiceProvider
{
    public const MODULE = 'payroll';

    /** RBAC-01: the module's permission catalogue (`payroll.resource.action`). */
    public const PERMISSIONS = [
        // PAY-02 to PAY-04: company settings, pay groups and items.
        'settings' => ['view', 'manage'],
        // PAY-05, PAY-06: employees' pay setup and items.
        'employee' => ['view', 'manage'],
        // PAY-13.
        'loan' => ['view', 'manage'],
        // PAY-14 to PAY-18: `approve` acts on the run's flow (WF-08), `post` locks and posts.
        'run' => ['view', 'prepare', 'approve', 'post'],
        // Decision 36: per-employee pay in a run (its lines); approvers judge runs by their totals without it.
        'pay' => ['view'],
        // PAY-19: payslips of others (the employee sees their own without it, PAY-24).
        'payslip' => ['view'],
        // PAY-20.
        'payment' => ['export'],
        // PAY-21.
        'return' => ['view', 'generate'],
    ];

    /** LAY-04: the module's lists that may have saved views. */
    public const LISTS = [
        'payroll-runs' => RunList::class,
        'payroll-run-lines' => RunLineList::class,
        'payroll-employees' => PayEmployeeList::class,
        'payroll-loans' => LoanList::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/payroll.php', 'payroll');
        $this->app->singleton(PayrollPack::class);
        $this->app->scoped(PayFields::class);
        $this->app->singleton(PaymentFormats::class, function () {
            $formats = new PaymentFormats;
            $formats->register(new GenericCsv);
            $formats->register(new MpesaB2cBulk($this->app->make(PayrollPack::class)));

            return $formats;
        });
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02, PAY-16: runs are approved through a flow (maker-checker).
        $this->app->make(DocumentTypeRegistry::class)->register(RunType::class);
        Event::listen(WorkflowCompleted::class, SettleRun::class);
        Event::listen(WorkflowCancelled::class, SettleRun::class);

        // PAY-05, PAY-11, PAY-12: HR's events feed Payroll's copy (HR-33).
        Event::listen([EmployeeHired::class, EmployeeTerminated::class, ContractChanged::class, LeaveApproved::class, LeaveCancelled::class, AttendancePeriodClosed::class], FollowHr::class);

        // NUM-01: run numbers.
        foreach (Numbers::types() as $type) {
            $this->app->make(DocumentNumberTypes::class)->register($type);
        }

        // TPL-01, PAY-19: payslips print from the `payroll.payslip` template.
        $this->app->make(DataSources::class)->register(new PayslipSource);

        // LAY-04: lists with saved views (field rules applied).
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $key => $definition) {
            $lists->register($key, $definition);
        }

        // Stage 7B backfill (ACC-29): posted runs raise PayrollPosted again, from their own lines.
        $this->app->make(EventReplays::class)->register(self::MODULE, function (CarbonImmutable $from, CarbonImmutable $to, callable $emit) {
            Run::query()->where('status', Run::POSTED)->where('posted_at', '>=', $from)->where('posted_at', '<=', $to)
                ->orderBy('posted_at')->orderBy('id')->get()->each(fn (Run $run) => $emit(PostingGroups::event($run)));
        });

        // PAY-20, PAY-21: files with account and ID numbers are built on demand; a person builds a few a minute.
        RateLimiter::for('payroll-files', fn (Request $request) => Limit::perMinute(30)->by('payroll-files|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
