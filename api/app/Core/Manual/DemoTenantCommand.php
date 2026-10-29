<?php

namespace App\Core\Manual;

use App\Core\Audit\AuditContext;
use App\Core\Identity\Models\User;
use App\Core\Manual\Demo\DemoApi;
use App\Core\Manual\Demo\DemoData;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Onboarding;
use App\Core\Onboarding\Sample\SampleData;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A demo tenant for the manual's screenshots: one company with two branches,
 * every module subscribed, sample items and customers, and one user per role
 * template with a known password and no second factor. Safe to run again: it
 * finds its tenant by a fixed id and only adds what is missing. Local and
 * testing environments only; it never reads or sends a code.
 */
class DemoTenantCommand extends Command
{
    public const TENANT_ID = '0192b7c0-0000-7000-8000-00000000d3a0';

    public const PASSWORD = 'Demo-Harbour-4242';

    public const DOMAIN = 'demo.example.com';

    /** Roles that work across the company; every other template is held at the main branch. */
    private const WIDE = ['owner', 'admin', 'accountant', 'hr_officer', 'payroll_officer', 'procurement_officer', 'approver', 'read_only_auditor', 'employee_self_service'];

    protected $signature = 'manual:demo-tenant {--only=* : Seed only these data sections (for example inventory), after the tenant}';

    protected $description = 'Create (or top up) the demo tenant the manual screenshots are taken in; local and testing only';

    public function handle(TenantContext $tenants, TenantProvisioner $provisioner, RoleTemplates $templates, ModuleRegistry $modules, SampleData $sample): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('manual:demo-tenant runs only when APP_ENV is local or testing.');

            return self::FAILURE;
        }

        // Approvals are made by queued listeners: run them inline so no queue worker is needed.
        config(['queue.default' => 'sync']);

        $id = self::TENANT_ID;
        $exists = $tenants->run($id, fn () => Tenant::query()->whereKey($id)->exists());

        $tenants->run($id, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id, $exists, $provisioner, $templates, $modules, $sample) {
            if (! $exists) {
                ['tenant' => $tenant] = $provisioner->provision($id, 'Demo Trading', 'KE', 'en');
                $owner = $this->user('owner', 'Demo owner');
                event(new TenantProvisioned($tenant, $owner));
            }

            $tenant = Tenant::query()->findOrFail($id);
            $this->branches();
            $this->trial();

            foreach ($modules->registered() as $module) {
                if ($module !== ModuleRegistry::CORE) {
                    $modules->activate($module);
                }
            }

            $templates->ensure($tenant, array_column($templates->all(), 'key'));
            $templates->refresh();
            $this->users($templates);

            $owner = User::query()->where('email', $this->email('owner'))->firstOrFail();

            if ($sample->current() === null) {
                $sample->load($owner);
            }
        }));

        (new DemoData(new DemoApi($this->email('owner'), self::PASSWORD), $this))->run($this->option('only'));

        $this->info('Demo tenant ready. Sign in as '.$this->email('owner').' with the password '.self::PASSWORD.'.');

        return self::SUCCESS;
    }

    /** A running trial, so the billing screens have a subscription to show (ONB-04). Every module is switched on below. */
    private function trial(): void
    {
        if (OnboardingState::query()->first()?->selection !== null) {
            return;
        }

        $owner = User::query()->where('email', $this->email('owner'))->firstOrFail();

        app(AuditContext::class)->actingAs($owner->id, [], function () {
            $onboarding = app(Onboarding::class);
            $onboarding->chooseBusinessType('retail');
            $onboarding->chooseModules(['modules' => ['pos', 'inventory', 'loyalty'], 'quantities' => ['outlet' => 4, 'device' => 12, 'employee' => 30], 'cycle' => 'monthly']);
        });
    }

    private function email(string $key): string
    {
        return 'demo-'.str_replace('_', '-', $key).'@'.self::DOMAIN;
    }

    private function user(string $key, string $name): User
    {
        return User::query()->where('email', $this->email($key))->first() ?? User::create([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'email' => $this->email($key),
            'password' => self::PASSWORD,
            'status' => 'active',
            'email_verified_at' => now(),
            'locale' => 'en',
        ]);
    }

    /** The main branch exists from provisioning; the demo adds a second one with an outlet. */
    private function branches(): void
    {
        if (Branch::query()->where('code', 'WEST')->exists()) {
            return;
        }

        $branch = Company::query()->orderBy('created_at')->firstOrFail()->branches()->create(['name' => 'Westlands branch', 'code' => 'WEST']);
        $branch->locations()->create(['name' => 'Westlands outlet', 'type' => 'outlet']);
    }

    private function users(RoleTemplates $templates): void
    {
        $main = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $roles = Role::query()->where('is_system', true)->get()->keyBy('template_key');

        foreach ($templates->all() as $template) {
            $key = $template['key'];
            $role = $roles->get($key);

            if ($role === null) {
                continue;
            }

            // Screenshots are taken without a second factor (the manual never reads a code).
            if ($role->requires_two_factor) {
                $role->forceFill(['requires_two_factor' => false])->save();
            }

            $user = $this->user($key, 'Demo '.str_replace('_', ' ', $key));
            $scope = in_array($key, self::WIDE, true) ? Scope::tenant(self::TENANT_ID) : Scope::branch($main->id);

            RoleAssignment::query()->firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
            ], ['tenant_id' => self::TENANT_ID]);
        }
    }
}
