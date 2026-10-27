<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\Scope;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\StaffHour;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-01 to SAL-05, SAL-15, SAL-28: the set-up endpoints, each refused to those without the permission, at another branch or in another tenant.
class BackOfficeApiTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    public function test_the_module_is_closed_to_a_tenant_without_it(): void
    {
        $other = $this->otherTenant();

        foreach (['services', 'package-types', 'staff', 'resources', 'appointments', 'queue', 'clients', 'packages', 'exceptions', 'commission-rules', 'commissions', 'commission-batches', 'reports/liability'] as $path) {
            $this->getJson("/api/v1/salon/{$path}", $this->headersFor($other['user']))->assertForbidden()->assertJsonPath('code', 'module_inactive');
        }

        $this->postJson('/api/v1/salon/device/sync', ['queue' => []], $this->headersFor($other['user']))->assertForbidden();
    }

    public function test_the_catalogue_lists_the_modules_permissions_and_the_roles_hold_the_right_ones(): void
    {
        $this->setUpSalon();
        $names = [];

        foreach (\Modules\Salon\SalonServiceProvider::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "salon.{$resource}.{$action}";
            }
        }

        $this->assertCount(36, $names);
        $granted = fn (string $template) => $this->inTenant(fn () => $this->roles->get($template)->permissions->pluck('name')->filter(fn ($n) => str_starts_with($n, 'salon.'))->values()->all());
        // The stylist works their own diary and reads their own pay; no fees, deposits, rules or approvals.
        $this->assertEqualsCanonicalizing(['salon.service.view', 'salon.staff.view', 'salon.resource.view', 'salon.appointment.view_own', 'salon.appointment.create', 'salon.appointment.serve', 'salon.queue.view',
            'salon.client.view', 'salon.client.manage', 'salon.client.notes', 'salon.package.view', 'salon.commission.view_own'], $granted('stylist'));
        // The front desk: no notes, refunds, waivers, pay or reports.
        $desk = $granted('receptionist');
        foreach (['salon.client.notes', 'salon.deposit.refund', 'salon.fee.waive', 'salon.commission.view', 'salon.commission.approve', 'salon.report.view', 'salon.settings.edit', 'salon.appointment.override'] as $no) {
            $this->assertNotContains($no, $desk);
        }
        // The manager holds everything but the company's settings and the commission rules; the branch manager the same; the admin sets up but does not decide pay.
        $manager = $granted('salon_manager');
        $this->assertEqualsCanonicalizing(array_values(array_diff($names, ['salon.settings.edit', 'salon.commission.rule'])), $manager);
        $this->assertEqualsCanonicalizing($manager, $granted('branch_manager'));
        $admin = $granted('admin');
        $this->assertContains('salon.commission.rule', $admin);
        $this->assertNotContains('salon.commission.approve', $admin);
        $this->assertNotContains('salon.client.notes', $admin);
        $this->assertSame(10000.0, (float) $this->inTenant(fn () => \App\Core\Rbac\Models\LimitRule::query()->where('role_id', $this->roles->get('salon_manager')->id)->where('key', 'max_refund_amount')->value('value')));
    }

    public function test_the_service_menu_is_set_up_by_the_admin_from_service_items_of_the_company(): void
    {
        $this->setUpSalon();
        $facial = $this->inTenant(fn () => Item::create(['code' => 'FACIAL', 'name' => 'Facial', 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]));
        $oil = $this->inTenant(fn () => Item::create(['code' => 'OIL', 'name' => 'Oil', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]));
        $body = ['company_id' => $this->acme->id, 'item_id' => $facial->id, 'duration_minutes' => 45, 'buffer_minutes' => 10, 'category' => 'Skin', 'staff_ids' => [$this->ann->id], 'products' => [['item_id' => $oil->id, 'qty' => '0.5']]];

        $this->api('POST', 'salon/services', $body, $this->annUser)->assertForbidden();
        $this->api('POST', 'salon/services', $body, $this->desk)->assertForbidden();
        $made = $this->api('POST', 'salon/services', $body, $this->owner)->assertCreated()->assertJsonPath('data.name', 'Facial')->assertJsonPath('data.products.0.item_name', 'Oil')->json('data');
        $this->assertSame(['Skin', 45, 10, [$this->ann->id]], [$made['category'], $made['duration_minutes'], $made['buffer_minutes'], $made['staff_ids']]);
        $this->assertSame(4, AuditEntry::where('action', 'salon.service.create')->count(), 'three on the menu and the new one');

        // One item is one service; stock cannot be the service; products are stock; ids are the tenant's and the company's.
        $this->api('POST', 'salon/services', $body, $this->owner)->assertStatus(422)->assertJsonPath('code', 'item_taken');
        $this->api('POST', 'salon/services', [...$body, 'item_id' => $oil->id], $this->owner)->assertStatus(422)->assertJsonPath('code', 'item_not_service');
        $this->api('POST', 'salon/services', [...$body, 'item_id' => $this->id()], $this->owner)->assertStatus(422);
        $other = $this->otherSalonTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, fn () => Item::create(['code' => 'ALIEN', 'name' => 'Alien', 'type' => 'service', 'base_uom_id' => \App\Core\MasterData\Items\Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count'])->id])->id);
        $this->api('POST', 'salon/services', [...$body, 'item_id' => $foreign], $this->owner)->assertStatus(422);
        $this->api('PUT', "salon/services/{$made['id']}", ['staff_ids' => [$this->id()]], $this->owner)->assertStatus(422);
        $this->api('PUT', "salon/services/{$made['id']}", ['duration_minutes' => 3], $this->owner)->assertStatus(422);
        $this->api('PUT', "salon/services/{$made['id']}", ['addon_ids' => [$made['id']]], $this->owner)->assertStatus(422)->assertJsonPath('code', 'addon_unknown');
        $this->api('PUT', "salon/services/{$made['id']}", ['addon_ids' => [$this->beard->id], 'online_bookable' => true, 'duration_minutes' => 60], $this->owner)->assertOk()->assertJsonPath('data.duration_minutes', 60)->assertJsonPath('data.addon_ids.0', $this->beard->id);
        $this->getJson("/api/v1/salon/services/{$made['id']}", $this->headersFor($other['user']))->assertStatus(405);
        $this->api('PUT', "salon/services/{$made['id']}", ['duration_minutes' => 20], $this->desk)->assertForbidden();
        $this->assertSame(4, count($this->api('GET', 'salon/services?company_id='.$this->acme->id, [], $this->desk)->assertOk()->json('data')));

        $this->api('POST', "salon/services/{$made['id']}/archive", [], $this->owner)->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->assertSame(3, count($this->api('GET', 'salon/services?company_id='.$this->acme->id, [], $this->desk)->json('data')));
        $this->assertSame(4, count($this->api('GET', 'salon/services?archived=1&company_id='.$this->acme->id, [], $this->desk)->json('data')));
        // Items on offer to make a service of: not the ones already on the menu.
        $offered = $this->api('GET', 'salon/service-items?company_id='.$this->acme->id, [], $this->owner)->assertOk()->json('data');
        $this->assertContains('Facial', array_column($offered, 'name'));
        $this->assertNotContains('Haircut', array_column($offered, 'name'));
        $this->assertNotContains('Oil', array_column($offered, 'name'));
        $this->assertSame(1, $this->inTenant(fn () => Service::query()->whereNotNull('archived_at')->count()));
    }

    public function test_package_types_are_sold_as_service_items_and_cover_services_of_the_company(): void
    {
        $this->setUpSalon();
        $item = $this->inTenant(fn () => Item::create(['code' => 'PKG10', 'name' => 'Ten colours', 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]));
        $body = ['company_id' => $this->acme->id, 'item_id' => $item->id, 'name' => 'Ten colours', 'sessions' => 10, 'validity_days' => 365, 'service_ids' => [$this->colour->id]];
        $this->api('POST', 'salon/package-types', $body, $this->desk)->assertForbidden();
        $made = $this->api('POST', 'salon/package-types', $body, $this->owner)->assertCreated()->json('data');
        $this->assertSame(['Ten colours', 'package', 10, [$this->colour->id]], [$made['name'], $made['kind'], $made['sessions'], $made['service_ids']]);
        $this->api('POST', 'salon/package-types', $body, $this->owner)->assertStatus(422);
        $this->api('POST', 'salon/package-types', [...$body, 'item_id' => $this->haircutItem->id, 'name' => 'Dup'], $this->owner)->assertStatus(422)->assertJsonPath('code', 'item_taken');
        $this->api('PUT', "salon/package-types/{$made['id']}", ['service_ids' => [$this->id()]], $this->owner)->assertStatus(422);
        $this->api('PUT', "salon/package-types/{$made['id']}", ['sessions' => 12, 'kind' => 'membership'], $this->owner)->assertOk()->assertJsonPath('data.sessions', 12)->assertJsonPath('data.kind', 'membership');
        $this->assertSame(2, count($this->api('GET', 'salon/package-types?company_id='.$this->acme->id, [], $this->desk)->json('data')));
        $this->api('POST', "salon/package-types/{$made['id']}/archive", [], $this->owner)->assertOk();
        $this->assertSame(1, $this->inTenant(fn () => PackageType::query()->whereNull('archived_at')->count()));
    }

    public function test_staff_hours_and_time_off_are_set_up_at_their_place(): void
    {
        $this->setUpSalon();
        $this->api('POST', 'salon/staff', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Cara', 'user_id' => $this->annUser->id], $this->manager)->assertStatus(422)->assertJsonPath('code', 'user_taken');
        $cara = $this->api('POST', 'salon/staff', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Cara', 'title' => 'Nail artist', 'color' => 'c3', 'accepts_online' => false], $this->manager)->assertCreated()->json('data');
        $this->assertSame(['Cara', 'c3', false], [$cara['name'], $cara['color'], $cara['accepts_online']]);
        $this->api('POST', 'salon/staff', ['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'name' => 'Dan'], $this->manager)->assertForbidden();
        $this->api('POST', 'salon/staff', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Eve'], $this->desk)->assertForbidden();
        $this->api('POST', 'salon/staff', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Eve', 'user_id' => $this->id()], $this->manager)->assertStatus(422);
        $this->api('POST', 'salon/staff', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Eve', 'color' => 'red'], $this->manager)->assertStatus(422);
        $this->api('PUT', "salon/staff/{$cara['id']}", ['branch_id' => $this->branchB->id], $this->manager)->assertForbidden();
        $this->api('PUT', "salon/staff/{$cara['id']}", ['title' => 'Senior nail artist'], $this->manager)->assertOk()->assertJsonPath('data.title', 'Senior nail artist');
        $this->api('GET', "salon/staff/{$cara['id']}", [], $this->desk)->assertOk()->assertJsonPath('data.name', 'Cara');

        // Weekly spans: replaced as a whole, overlaps and a break outside the work refused, and the old list is gone.
        $span = fn (int $day, string $from, string $to, string $kind = 'work') => ['branch_id' => $this->branchA->id, 'weekday' => $day, 'kind' => $kind, 'start' => $from, 'end' => $to];
        $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [$span(1, '09:00', '13:00'), $span(1, '12:00', '17:00')]], $this->manager)->assertStatus(422)->assertJsonPath('code', 'hours_overlap');
        $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [$span(1, '09:00', '12:00'), $span(1, '12:30', '13:00', 'break')]], $this->manager)->assertStatus(422)->assertJsonPath('code', 'break_outside_work');
        $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [$span(1, '13:00', '09:00')]], $this->manager)->assertStatus(422);
        $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [$span(1, '09:00', '17:00'), $span(1, '12:30', '13:30', 'break'), $span(2, '10:00', '16:00')]], $this->desk)->assertForbidden();
        $hours = $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [$span(1, '09:00', '17:00'), $span(1, '12:30', '13:30', 'break'), $span(2, '10:00', '16:00')]], $this->manager)->assertOk()->json('data');
        $this->assertSame([[1, 'work', '09:00', '17:00'], [1, 'break', '12:30', '13:30'], [2, 'work', '10:00', '16:00']], array_map(fn ($h) => [$h['weekday'], $h['kind'], $h['start'], $h['end']], $hours));
        $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [$span(3, '09:00', '12:00')]], $this->manager)->assertOk();
        $this->assertSame(1, $this->inTenant(fn () => StaffHour::query()->where('staff_id', $cara['id'])->count()));
        // A branch outside the staff member's reach is not an option.
        $this->api('PUT', "salon/staff/{$cara['id']}/hours", ['hours' => [['branch_id' => $this->branchB->id, 'weekday' => 1, 'start' => '09:00', 'end' => '17:00']]], $this->manager)->assertStatus(422)->assertJsonPath('code', 'branch_unknown');

        $off = $this->api('POST', "salon/staff/{$cara['id']}/time-off", ['starts_at' => $this->at('09:00', 2), 'ends_at' => $this->at('17:00', 3), 'reason' => 'training'], $this->manager)->assertCreated()->json('data');
        $this->api('POST', "salon/staff/{$cara['id']}/time-off", ['starts_at' => $this->at('09:00', 2), 'ends_at' => $this->at('09:00', 1), 'reason' => 'training'], $this->manager)->assertStatus(422);
        $this->api('POST', "salon/staff/{$cara['id']}/time-off", ['starts_at' => $this->at('09:00', 2), 'ends_at' => $this->at('09:00', 3), 'reason' => 'party'], $this->manager)->assertStatus(422);
        $this->assertCount(1, $this->api('GET', "salon/staff/{$cara['id']}/time-off", [], $this->desk)->assertOk()->json('data'));
        $this->api('POST', "salon/time-off/{$off['id']}/cancel", [], $this->desk)->assertForbidden();
        $this->api('POST', "salon/time-off/{$off['id']}/cancel", [], $this->manager)->assertOk();
        $this->assertCount(0, $this->api('GET', "salon/staff/{$cara['id']}/time-off", [], $this->desk)->json('data'));
        $this->api('POST', "salon/staff/{$cara['id']}/archive", [], $this->manager)->assertOk();
        $this->assertSame(2, count($this->api('GET', 'salon/staff?company_id='.$this->acme->id, [], $this->desk)->json('data')));
        $this->assertSame(1, $this->inTenant(fn () => Staff::query()->whereNotNull('archived_at')->count()));
    }

    public function test_chairs_and_rooms_belong_to_a_branch(): void
    {
        $this->setUpSalon();
        $made = $this->api('POST', 'salon/resources', ['branch_id' => $this->branchA->id, 'name' => 'Spa room', 'kind' => 'room'], $this->manager)->assertCreated()->json('data');
        $this->api('POST', 'salon/resources', ['branch_id' => $this->branchA->id, 'name' => 'spa room'], $this->manager)->assertStatus(422)->assertJsonPath('code', 'name_taken');
        $this->api('POST', 'salon/resources', ['branch_id' => $this->branchB->id, 'name' => 'Other'], $this->manager)->assertForbidden();
        $this->api('POST', 'salon/resources', ['branch_id' => $this->branchA->id, 'name' => 'Nope'], $this->desk)->assertForbidden();
        $this->api('PUT', "salon/resources/{$made['id']}", ['name' => 'Spa room 1'], $this->manager)->assertOk()->assertJsonPath('data.name', 'Spa room 1');
        $this->assertCount(3, $this->api('GET', 'salon/resources?branch_id='.$this->branchA->id, [], $this->desk)->assertOk()->json('data'));
        $this->api('GET', 'salon/resources?branch_id='.$this->branchB->id, [], $this->desk)->assertForbidden();
        $this->api('POST', "salon/resources/{$made['id']}/archive", [], $this->manager)->assertOk();
        $this->assertCount(2, $this->api('GET', 'salon/resources?branch_id='.$this->branchA->id, [], $this->desk)->json('data'));
        $this->assertSame(1, $this->inTenant(fn () => Resource::query()->whereNotNull('archived_at')->count()));
    }

    public function test_the_public_page_is_set_up_and_its_code_can_be_changed_by_those_who_may(): void
    {
        $this->setUpSalon();
        $page = $this->api('GET', "salon/branches/{$this->branchA->id}/online", [], $this->desk)->assertOk()->json('data');
        $this->assertSame(['testcode0001', true], [$page['public_code'], $page['enabled']]);

        // Branch B has no page yet: reading makes it, switched off, with a code nobody can guess.
        $made = $this->api('GET', "salon/branches/{$this->branchB->id}/online", [], $this->owner)->assertOk()->json('data');
        $this->assertSame([false, 12], [$made['enabled'], strlen($made['public_code'])]);
        $this->api('GET', "salon/branches/{$this->branchB->id}/online", [], $this->desk)->assertForbidden();

        $this->api('PUT', "salon/branches/{$this->branchA->id}/online", ['public_name' => 'Glow Salon', 'lead_minutes' => 120, 'max_days_ahead' => 30], $this->annUser)->assertForbidden();
        $this->api('PUT', "salon/branches/{$this->branchA->id}/online", ['public_name' => 'Glow Salon', 'lead_minutes' => 120, 'max_days_ahead' => 30], $this->desk)->assertForbidden();
        $this->api('PUT', "salon/branches/{$this->branchA->id}/online", ['public_name' => 'Glow Salon', 'lead_minutes' => 120, 'max_days_ahead' => 30], $this->manager)->assertOk()->assertJsonPath('data.lead_minutes', 120);
        $this->api('PUT', "salon/branches/{$this->branchA->id}/online", ['max_days_ahead' => 0], $this->manager)->assertStatus(422);
        $this->api('PUT', "salon/branches/{$this->branchA->id}/online", ['location_id' => $this->locationB->id], $this->manager)->assertStatus(422)->assertJsonPath('code', 'location_unknown');
        $this->getJson('/api/v1/salon-booking/testcode0001')->assertOk()->assertJsonPath('data.name', 'Glow Salon');

        $new = $this->api('POST', "salon/branches/{$this->branchA->id}/online/regenerate-code", [], $this->manager)->assertOk()->json('data.public_code');
        $this->assertNotSame('testcode0001', $new);
        $this->getJson('/api/v1/salon-booking/testcode0001')->assertNotFound();
        $this->getJson("/api/v1/salon-booking/{$new}")->assertOk();
        $this->api('POST', "salon/branches/{$this->branchB->id}/online/regenerate-code", [], $this->manager)->assertForbidden();
        $this->api('PUT', "salon/branches/{$this->branchA->id}/online", ['enabled' => false], $this->manager)->assertOk();
        $this->getJson("/api/v1/salon-booking/{$new}")->assertNotFound();
    }

    public function test_the_companys_settings_are_read_by_the_salon_staff_and_changed_by_the_admin(): void
    {
        $this->setUpSalon();
        $url = "salon/companies/{$this->acme->id}/settings";
        $this->api('GET', $url, [], $this->desk)->assertOk()->assertJsonPath('data.slot_minutes', 15)->assertJsonPath('data.no_show_fee_percent', '20');
        $this->api('GET', $url, [], $this->annUser)->assertOk();
        $this->api('PUT', $url, ['deposit_percent' => 30], $this->manager)->assertForbidden();
        $this->api('PUT', $url, ['deposit_percent' => 30, 'slot_minutes' => 10, 'cancel_window_hours' => 48], $this->owner)->assertOk()->assertJsonPath('data.deposit_percent', '30')->assertJsonPath('data.slot_minutes', 10);
        foreach ([['slot_minutes' => 7], ['deposit_percent' => 101], ['hold_minutes' => 1], ['guest_retention_days' => 5], ['reminder_hours' => 0], ['tip_item_id' => $this->id()]] as $bad) {
            $this->api('PUT', $url, $bad, $this->owner)->assertStatus(422);
        }
        $this->api('PUT', $url, ['fee_item_id' => $this->soap->id], $this->owner)->assertStatus(422)->assertJsonPath('code', 'item_not_service');
        // The prepaid-value payment method exists once the company is set up, and only once.
        $this->api('PUT', $url, ['commission_item_code' => 'COMM'], $this->owner)->assertOk();
        $this->assertSame(1, $this->inTenant(fn () => PaymentMethod::query()->where('type', 'salon_credit')->count()));
        $this->assertSame(1, AuditEntry::where('action', 'salon.settings.update')->count() >= 1 ? 1 : 0);
        $other = $this->otherSalonTenant();
        $this->getJson("/api/v1/salon/companies/{$this->acme->id}/settings", $this->headersFor($other['user']))->assertNotFound();
        $this->assertNotNull(Scope::class);
    }

    public function test_the_calendar_shows_each_staff_members_day_and_a_stylist_only_their_own_appointments(): void
    {
        $this->setUpSalon();
        $this->book('10:00');
        $this->book('11:00', $this->bob);
        $this->inTenant(fn () => \Modules\Salon\Models\TimeOff::create(['staff_id' => $this->bob->id, 'starts_at' => $this->at('15:00'), 'ends_at' => $this->at('16:00'), 'reason' => 'other']));

        $day = $this->api('GET', 'salon/calendar?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today]), [], $this->desk)->assertOk()->json('data');
        $this->assertSame([$this->today, 'Africa/Nairobi', 2], [$day['date'], $day['timezone'], count($day['appointments'])]);
        $ann = collect($day['staff'])->firstWhere('name', 'Ann');
        $this->assertSame([[$this->at('09:00'), $this->at('17:00')]], $ann['work']);
        $this->assertSame([[$this->at('13:00'), $this->at('14:00')]], $ann['off']);
        $this->assertContains([$this->at('15:00'), $this->at('16:00')], collect($day['staff'])->firstWhere('name', 'Bob')['off']);
        $this->assertCount(2, $day['resources']);

        $mine = $this->api('GET', 'salon/calendar?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today]), [], $this->annUser)->assertOk()->json('data');
        $this->assertSame(['Haircut'], collect($mine['appointments'])->flatMap(fn ($a) => array_column($a['lines'], 'service_name'))->unique()->values()->all());
        $this->assertCount(1, $mine['appointments']);
        $this->api('GET', 'salon/calendar?'.http_build_query(['branch_id' => $this->branchB->id, 'date' => $this->today]), [], $this->desk)->assertForbidden();
        // A week at once.
        $week = $this->api('GET', 'salon/calendar?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today, 'days' => 7]), [], $this->desk)->assertOk()->json('data');
        $this->assertCount(7, array_filter($week['staff'][0]['work'], fn ($s) => true));
    }
}
