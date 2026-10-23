<?php

namespace Modules\HR\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\HR\HrServiceProvider;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08, HR-09: what the system roles may do in HR once the
// module is active (the sensitive groups only for HR and payroll), and
// nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    private const SENSITIVE = ['hr.bank.view', 'hr.disciplinary.view', 'hr.identity.view', 'hr.medical.view', 'hr.pay.view'];

    /** @return list<string> every hr.* permission */
    private static function all(): array
    {
        return collect(HrServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "hr.{$resource}.{$action}", $actions))
            ->sort()->values()->all();
    }

    /** @return list<string> the hr.* permissions $template holds at branch A */
    private function held(string $template): array
    {
        $user = $this->userWith($template, Scope::branch($this->branchA->id));

        return $this->inTenant(fn () => collect(self::all())
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::branch($this->branchA->id)))
            ->values()->all());
    }

    public function test_the_catalogue_has_thirty_seven_permissions(): void
    {
        // hr.device.view (MKT-04): the biometric connector reads the devices.
        $this->assertCount(37, self::all());
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $this->setUpHr();
        $views = array_values(array_filter(self::all(), fn (string $p) => str_ends_with($p, '.view')));

        $this->assertSame(self::all(), $this->held('owner'));
        $this->assertSame(self::all(), $this->held('hr_officer'));
        $this->assertSame(array_values(array_diff($views, self::SENSITIVE)), $this->held('admin'));
        $this->assertSame(array_values(array_diff($views, self::SENSITIVE)), $this->held('read_only_auditor'));
        $this->assertSame(['hr.appraisal.manage', 'hr.appraisal.view', 'hr.attendance.manage', 'hr.attendance.view', 'hr.checklist.view', 'hr.contract.view', 'hr.employee.view',
            'hr.leave.approve', 'hr.leave.request', 'hr.leave.view', 'hr.position.view', 'hr.roster.manage', 'hr.roster.view', 'hr.swap.approve'], $this->held('branch_manager'));
        $this->assertSame(['hr.attendance.view', 'hr.bank.view', 'hr.contract.view', 'hr.employee.view', 'hr.identity.view', 'hr.leave.view', 'hr.pay.view'], $this->held('payroll_officer'));
        $this->assertSame(['hr.leave.approve', 'hr.leave.view', 'hr.roster.view', 'hr.swap.approve'], $this->held('approver'));
        $this->assertSame([], $this->held('cashier'));
        $this->assertSame([], $this->held('employee_self_service'));

        // RBAC-08: with the module off, the same roles grant nothing of it and its routes are closed.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('hr'));
        $this->assertSame([], $this->held('hr_officer'));
        $this->getJson('/api/v1/hr/employees', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
