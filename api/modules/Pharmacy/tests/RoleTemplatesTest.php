<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Identity\Pin\Pins;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Pharmacy\PharmacyServiceProvider;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08, PHA-29: what the system roles may do in the pharmacy once
// the module is active, and nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private const ALL = [
        'pharmacy.dispense.authorise', 'pharmacy.dispense.review',
        'pharmacy.item.configure', 'pharmacy.item.view',
        'pharmacy.lot.quarantine', 'pharmacy.lot.receive', 'pharmacy.lot.transfer', 'pharmacy.lot.view',
        'pharmacy.prescription.cancel', 'pharmacy.prescription.create', 'pharmacy.prescription.image', 'pharmacy.prescription.view',
        'pharmacy.recall.customers', 'pharmacy.recall.issue', 'pharmacy.recall.lift', 'pharmacy.recall.view',
        'pharmacy.register.adjust', 'pharmacy.register.print', 'pharmacy.register.view',
        'pharmacy.report.view',
        'pharmacy.settings.edit', 'pharmacy.settings.view',
        'pharmacy.writeoff.approve', 'pharmacy.writeoff.cancel', 'pharmacy.writeoff.create', 'pharmacy.writeoff.view',
    ];

    /** @return list<string> the pharmacy.* permissions $template holds at location A */
    private function held(string $template): array
    {
        $user = $this->userWith($template, Scope::location($this->locationA->id));

        return $this->inTenant(fn () => collect(self::ALL)
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::location($this->locationA->id)))
            ->values()->all());
    }

    private function without(array ...$remove): array
    {
        return array_values(array_diff(self::ALL, ...$remove));
    }

    public function test_the_catalogue_has_every_pharmacy_permission(): void
    {
        $this->assertSame(self::ALL, collect(PharmacyServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "pharmacy.{$resource}.{$action}", $actions))
            ->sort()->values()->all());
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $this->setUpPharmacy();

        // The pharmacist authorises restricted dispensing and keeps the register; the company's settings are the Admin's.
        $this->assertSame($this->without(['pharmacy.settings.edit']), $this->held('pharmacist'));
        // The branch manager decides write-offs and runs the place, but is not a pharmacist.
        $this->assertSame($this->without(['pharmacy.dispense.authorise', 'pharmacy.settings.edit', 'pharmacy.item.configure']), $this->held('branch_manager'));
        // The technician works the stock and the prescriptions, never the controlled decisions.
        $this->assertSame([
            'pharmacy.item.view', 'pharmacy.lot.receive', 'pharmacy.lot.transfer', 'pharmacy.lot.view', 'pharmacy.prescription.create', 'pharmacy.prescription.view',
            'pharmacy.recall.view', 'pharmacy.register.view', 'pharmacy.report.view', 'pharmacy.writeoff.create', 'pharmacy.writeoff.view',
        ], $this->held('pharmacy_technician'));
        $this->assertSame(['pharmacy.item.view', 'pharmacy.lot.receive', 'pharmacy.lot.transfer', 'pharmacy.lot.view', 'pharmacy.report.view', 'pharmacy.writeoff.create', 'pharmacy.writeoff.view'], $this->held('storekeeper'));
        $this->assertSame(['pharmacy.prescription.create'], $this->held('cashier'));
        $this->assertSame(['pharmacy.item.view', 'pharmacy.lot.view', 'pharmacy.report.view', 'pharmacy.writeoff.view'], $this->held('accountant'));
        $this->assertSame(['pharmacy.writeoff.approve', 'pharmacy.writeoff.view'], $this->held('approver'));
        // Patients are personal data: the Admin configures the module and reads its records but not prescriptions; nor does an auditor.
        $admin = array_diff($this->held('admin'), ['pharmacy.prescription.view']);
        $this->assertSame($this->held('admin'), array_values($admin));
        $this->assertContains('pharmacy.settings.edit', $this->held('admin'));
        $this->assertContains('pharmacy.item.configure', $this->held('admin'));
        $this->assertNotContains('pharmacy.dispense.authorise', $this->held('admin'));
        $this->assertNotContains('pharmacy.prescription.view', $this->held('read_only_auditor'));
        $this->assertContains('pharmacy.register.view', $this->held('read_only_auditor'));
        $this->assertSame([], array_filter($this->held('read_only_auditor'), fn (string $p) => ! str_ends_with($p, '.view')));
        $this->assertSame([], $this->held('employee_self_service'));
        $this->assertSame([], $this->held('waiter'));
        // Only a tenant-wide Owner holds all of them (the `*` pattern).
        $this->assertSame(self::ALL, $this->inTenant(fn () => collect(self::ALL)->filter(fn (string $p) => app(ScopeResolver::class)->can($this->owner, $p))->values()->all()));
    }

    public function test_nothing_is_granted_while_the_module_is_off(): void
    {
        $this->setUpPharmacy();
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pharmacy'));

        $this->assertSame([], $this->held('pharmacist'));
        $this->assertSame([], $this->inTenant(fn () => collect(self::ALL)->filter(fn (string $p) => app(ScopeResolver::class)->can($this->owner, $p))->values()->all()));
    }

    public function test_a_pharmacist_needs_a_six_digit_pin_because_they_approve_at_the_till(): void
    {
        $this->setUpPharmacy();
        $pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));

        $this->assertTrue($this->inTenant(fn () => app(Pins::class)->needsSixDigits($pharmacist)));
        $this->assertFalse($this->inTenant(fn () => app(Pins::class)->needsSixDigits($technician)));
    }
}
