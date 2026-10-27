<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-03, RBAC-08, HOT-33: what the system roles may do in the hotel once the module is active, and nothing of it while it is not.
class RoleTemplatesTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    /** @return list<string> every hotel.* permission, sorted */
    private function all(): array
    {
        return collect(HotelServiceProvider::PERMISSIONS)
            ->flatMap(fn (array $actions, string $resource) => array_map(fn (string $action) => "hotel.{$resource}.{$action}", $actions))
            ->sort()->values()->all();
    }

    /** @return list<string> the hotel.* permissions $template holds at branch A */
    private function held(string $template): array
    {
        $user = $this->userWith($template, Scope::branch($this->branchA->id));

        return $this->inTenant(fn () => collect($this->all())
            ->filter(fn (string $permission) => app(ScopeResolver::class)->can($user, $permission, Scope::branch($this->branchA->id)))
            ->values()->all());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    public function test_the_catalogue_has_thirty_three_permissions_named_resource_action(): void
    {
        $this->assertCount(33, $this->all());

        foreach ($this->all() as $permission) {
            $this->assertMatchesRegularExpression('/^hotel\.[a-z_]+\.[a-z_]+$/', $permission);
        }
    }

    public function test_roles_by_job(): void
    {
        $all = $this->all();
        $without = fn (array ...$remove) => array_values(array_diff($all, ...$remove));

        // The branch manager runs the property; the company's settings are the Admin's.
        $this->assertSame($without(['hotel.settings.edit']), $this->held('branch_manager'));

        $reception = $this->held('receptionist');
        foreach (['hotel.reservation.create', 'hotel.reservation.cancel', 'hotel.stay.checkin', 'hotel.stay.checkout', 'hotel.folio.payment', 'hotel.folio.refund', 'hotel.guest.identity'] as $permission) {
            $this->assertContains($permission, $reception);
        }
        // Not rates, voids, fee waivers, leaving a balance, early check-in, the audit or the settings.
        foreach (['hotel.rate.manage', 'hotel.rate.override', 'hotel.folio.void', 'hotel.fee.waive', 'hotel.folio.settle_open', 'hotel.stay.checkin_early', 'hotel.audit.run', 'hotel.settings.edit', 'hotel.room.manage'] as $permission) {
            $this->assertNotContains($permission, $reception);
        }

        $this->assertSame(['hotel.housekeeping.update', 'hotel.housekeeping.view', 'hotel.room.view'], $this->held('housekeeping'));
        $this->assertSame([
            'hotel.audit.run', 'hotel.audit.view', 'hotel.charge.resolve', 'hotel.folio.view', 'hotel.guest.view', 'hotel.housekeeping.view', 'hotel.report.view',
            'hotel.reservation.cancel', 'hotel.reservation.view', 'hotel.room.view',
        ], $this->held('night_auditor'));
        $this->assertSame(['hotel.audit.view', 'hotel.folio.view', 'hotel.rate.view', 'hotel.report.view', 'hotel.room.view'], $this->held('accountant'));

        // Cashiers and waiters charge a room through POS, which holds no hotel permission.
        $this->assertSame([], $this->held('cashier'));
        $this->assertSame([], $this->held('waiter'));
        $this->assertSame([], $this->held('employee_self_service'));

        // The Admin configures and reads, but guest identity documents are not a view.
        $admin = $this->held('admin');
        foreach (['hotel.settings.edit', 'hotel.room.manage', 'hotel.rate.manage', 'hotel.folio.view'] as $permission) {
            $this->assertContains($permission, $admin);
        }
        $this->assertNotContains('hotel.guest.identity', $admin);
        $this->assertNotContains('hotel.folio.charge', $admin);

        // An auditor reads and cannot reveal identity documents.
        $auditor = $this->held('read_only_auditor');
        $this->assertNotContains('hotel.guest.identity', $auditor);
        $this->assertSame([], array_filter($auditor, fn (string $p) => ! str_ends_with($p, '.view')));

        // Only a tenant-wide Owner holds all of them (the `*` pattern).
        $this->assertSame($all, $this->inTenant(fn () => collect($all)->filter(fn (string $p) => app(ScopeResolver::class)->can($this->owner, $p))->values()->all()));
    }

    public function test_nothing_is_granted_while_the_module_is_off(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('hotel'));

        $this->assertSame([], $this->held('receptionist'));
        $this->assertSame([], $this->inTenant(fn () => collect($this->all())->filter(fn (string $p) => app(ScopeResolver::class)->can($this->owner, $p))->values()->all()));
    }

    public function test_a_role_at_one_property_is_refused_at_another(): void
    {
        $user = $this->userWith('receptionist', Scope::branch($this->branchA->id));

        $this->assertTrue($this->inTenant(fn () => app(ScopeResolver::class)->can($user, 'hotel.stay.checkin', Scope::branch($this->branchA->id))));
        $this->assertFalse($this->inTenant(fn () => app(ScopeResolver::class)->can($user, 'hotel.stay.checkin', Scope::branch($this->branchB->id))));
    }
}
