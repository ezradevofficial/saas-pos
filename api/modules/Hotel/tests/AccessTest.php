<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-01 to RBAC-04, RBAC-08, TEN-01: every endpoint refuses someone without the permission, someone who holds it at another property,
// and another tenant's records; housekeepers see rooms and tasks and nothing of the guests.
class AccessTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> method, uri, body for one booking, folio, task, room and so on */
    private function endpoints(array $stay, string $folio, string $line, string $task, string $exception): array
    {
        $branch = $this->branchA->id;
        $company = $this->acme->id;
        $room = $this->rooms['103']->id;

        return [
            ['GET', "companies/{$company}/hotel-settings", []],
            ['PUT', "companies/{$company}/hotel-settings", ['check_in_time' => '13:00']],
            ['GET', 'hotel/room-types', []],
            ['POST', 'hotel/room-types', ['company_id' => $company, 'code' => 'X', 'name' => 'X']],
            ['PATCH', "hotel/room-types/{$this->deluxe->id}", ['name' => 'X']],
            ['POST', "hotel/room-types/{$this->deluxe->id}/archive", []],
            ['GET', 'hotel/rooms', []],
            ['POST', 'hotel/rooms', ['branch_id' => $branch, 'room_type_id' => $this->deluxe->id, 'number' => '777']],
            ['PATCH', "hotel/rooms/{$room}", ['floor' => '9']],
            ['POST', "hotel/rooms/{$room}/archive", []],
            ['POST', "hotel/rooms/{$room}/status", ['status' => 'vacant_dirty']],
            ['GET', 'hotel/rate-plans', []],
            ['POST', 'hotel/rate-plans', ['room_type_id' => $this->deluxe->id, 'code' => 'ZZ', 'name' => 'Z', 'board' => 'room_only', 'currency' => 'KES', 'weekday_rate_minor' => 1, 'weekend_rate_minor' => 1]],
            ['GET', "hotel/rate-plans/{$this->bb->id}", []],
            ['PATCH', "hotel/rate-plans/{$this->bb->id}", ['name' => 'Y']],
            ['POST', "hotel/rate-plans/{$this->bb->id}/archive", []],
            ['GET', "hotel/rate-quote?rate_plan_id={$this->bb->id}&arrival_on={$this->day(10)}&departure_on={$this->day(11)}", []],
            ['GET', "hotel/availability?branch_id={$branch}&from={$this->day(0)}&to={$this->day(3)}", []],
            ['GET', 'hotel/guests', []],
            ['POST', 'hotel/guests', ['company_id' => $company, 'name' => 'New Guest']],
            ['GET', "hotel/guests/{$this->guest->id}", []],
            ['PATCH', "hotel/guests/{$this->guest->id}", ['notes' => 'x']],
            ['GET', 'hotel/reservations', []],
            ['POST', 'hotel/reservations', $this->bookingBody(1, ['arrival_on' => $this->day(8), 'departure_on' => $this->day(9)])],
            ['GET', "hotel/reservations/{$stay['reservation_id']}", []],
            ['POST', 'hotel/walk-ins', ['branch_id' => $branch, 'guest_id' => $this->guest->id, 'room_id' => $room, 'rate_plan_id' => $this->bb->id, 'departure_on' => $this->day(1)]],
            ['PATCH', "hotel/stays/{$stay['id']}", ['notes' => 'x']],
            ['POST', "hotel/stays/{$stay['id']}/assign-room", ['room_id' => $room]],
            ['POST', "hotel/stays/{$stay['id']}/check-in", []],
            ['POST', "hotel/stays/{$stay['id']}/check-out", []],
            ['POST', "hotel/stays/{$stay['id']}/move-room", ['room_id' => $room, 'reason' => 'noise']],
            ['POST', "hotel/stays/{$stay['id']}/cancel", ['reason' => 'noise']],
            ['POST', "hotel/stays/{$stay['id']}/no-show", []],
            ['GET', 'hotel/folios', []],
            ['GET', "hotel/folios/{$folio}", []],
            ['GET', "hotel/folios/{$folio}/print", []],
            ['POST', "hotel/folios/{$folio}/charges", ['amount_minor' => 100, 'description' => 'x']],
            ['POST', "hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 100]],
            ['POST', "hotel/folios/{$folio}/refunds", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 100, 'reason' => 'noise']],
            ['POST', "hotel/folios/{$folio}/transfers", ['to_folio_id' => $folio, 'line_ids' => [$line], 'reason' => 'noise']],
            ['POST', "hotel/folios/{$folio}/lines/{$line}/void", ['reason' => 'noise']],
            ['POST', "hotel/folios/{$folio}/settle", []],
            ['GET', "hotel/room-charges/lookup?branch_id={$branch}&room=101", []],
            ['GET', 'hotel/charge-exceptions', []],
            ['POST', "hotel/charge-exceptions/{$exception}/resolve", ['resolution' => 'written_off', 'note' => 'noise']],
            ['GET', "hotel/housekeeping/board?branch_id={$branch}", []],
            ['GET', 'hotel/housekeeping/tasks', []],
            ['POST', 'hotel/housekeeping/tasks', ['room_id' => $room, 'kind' => 'deep_clean']],
            ['POST', "hotel/housekeeping/tasks/{$task}/assign", ['assigned_to' => null]],
            ['POST', "hotel/housekeeping/tasks/{$task}/start", []],
            ['POST', "hotel/housekeeping/tasks/{$task}/finish", []],
            ['POST', "hotel/housekeeping/tasks/{$task}/inspect", []],
            ['GET', "hotel/night-audits?branch={$branch}", []],
            ['POST', 'hotel/night-audits', ['branch_id' => $branch, 'business_date' => $this->day(0), 'close_early' => true]],
            ['GET', "hotel/reports/occupancy?branch_id={$branch}&from={$this->day(0)}&to={$this->day(1)}", []],
            ['GET', "hotel/reports/arrivals?branch={$branch}", []],
            ['GET', "hotel/reports/departures?branch={$branch}", []],
            ['GET', "hotel/reports/in-house?branch={$branch}", []],
            ['GET', "hotel/reports/no-shows?branch={$branch}", []],
            ['GET', 'hotel/reports/folio-balances', []],
            ['GET', 'hotel/reports/housekeeping', []],
        ];
    }

    private function fixtures(): array
    {
        $stay = $this->book(2)['stays'][0];
        $folio = $this->inTenant(fn () => Folio::query()->where('stay_id', $stay['id'])->value('id'));
        $line = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['amount_minor' => 5000, 'description' => 'Parking'], $this->headersFor())->assertCreated()->json('data.id');
        $task = $this->inTenant(fn () => HousekeepingTask::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'room_id' => $this->rooms['103']->id, 'kind' => 'deep_clean', 'status' => 'pending', 'business_date' => $this->today])->id);
        $exception = $this->inTenant(fn () => ChargeException::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'source' => 'pos.sale', 'source_id' => 'pay-1', 'currency' => 'KES', 'amount_minor' => 100, 'reason' => 'room_unknown', 'status' => 'open'])->id);

        return [$stay, $folio, $line, $task, $exception];
    }

    public function test_every_endpoint_refuses_a_user_with_no_hotel_permission_and_one_who_holds_them_at_another_property(): void
    {
        [$stay, $folio, $line, $task, $exception] = $this->fixtures();
        $nobody = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $elsewhere = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $endpoints = $this->endpoints($stay, $folio, $line, $task, $exception);
        $leaks = [];

        foreach ($endpoints as [$method, $uri, $body]) {
            foreach ([['nobody', $nobody], ['other branch', $elsewhere]] as [$who, $user]) {
                // Guests are the company's shared register: front-desk staff at any property keep it.
                if ($who === 'other branch' && str_starts_with($uri, 'hotel/guests') && $method !== 'GET') {
                    continue;
                }

                $status = $this->json($method, '/api/v1/'.$uri, $body, $this->headersFor($user))->getStatusCode();

                // Lists answer an empty page to someone who sees nothing (RBAC-04); everything else is refused or not found.
                $listOrEmpty = $method === 'GET' && in_array($status, [200], true);

                if ($status !== 403 && $status !== 404 && ! $listOrEmpty) {
                    $leaks[] = "{$who}: {$method} {$uri} answered {$status}";
                }
            }
        }

        $this->assertSame([], $leaks);

        // The lists that answered 200 showed nothing of the property.
        foreach (['hotel/reservations', 'hotel/folios', 'hotel/charge-exceptions', 'hotel/housekeeping/tasks', 'hotel/rooms'] as $uri) {
            $this->getJson('/api/v1/'.$uri, $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_the_system_roles_can_do_what_their_jobs_need_and_no_more(): void
    {
        [$stay, $folio] = $this->fixtures();
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $housekeeper = $this->userWith('housekeeping', Scope::branch($this->branchA->id));
        $auditor = $this->userWith('night_auditor', Scope::branch($this->branchA->id));
        $h = fn ($u) => $this->headersFor($u);

        // The front desk takes a booking and a payment, cannot change rates or the night audit.
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['arrival_on' => $this->day(5), 'departure_on' => $this->day(6)]), $h($receptionist))->assertCreated();
        $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 1000], $h($receptionist))->assertCreated();
        $this->patchJson("/api/v1/hotel/rate-plans/{$this->bb->id}", ['name' => 'Cheaper'], $h($receptionist))->assertForbidden();
        $this->putJson("/api/v1/companies/{$this->acme->id}/hotel-settings", ['check_in_time' => '13:00'], $h($receptionist))->assertStatus(404);

        // Housekeeping marks rooms and sees tasks; guests and folios are not theirs.
        $this->postJson('/api/v1/hotel/rooms/'.$this->rooms['101']->id.'/status', ['status' => 'vacant_dirty'], $h($housekeeper))->assertOk();
        $this->postJson('/api/v1/hotel/rooms/'.$this->rooms['101']->id.'/status', ['status' => 'out_of_order', 'reason' => 'x'], $h($housekeeper))->assertStatus(422);
        $this->getJson('/api/v1/hotel/housekeeping/my-tasks', $h($housekeeper))->assertOk();
        $this->getJson('/api/v1/hotel/guests', $h($housekeeper))->assertForbidden();
        $this->getJson("/api/v1/hotel/folios/{$folio}", $h($housekeeper))->assertForbidden();
        $this->getJson('/api/v1/hotel/reservations', $h($housekeeper))->assertForbidden();

        // The night auditor audits and reads, but does not take payments.
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $h($auditor))->assertCreated();
        $this->getJson("/api/v1/hotel/folios/{$folio}", $h($auditor))->assertOk();
        $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 1000], $h($auditor))->assertForbidden();
        $this->assertNotNull($stay);
    }

    public function test_another_tenants_records_are_not_reachable_through_any_id(): void
    {
        [$stay, $folio, $line, $task, $exception] = $this->fixtures();
        $other = $this->otherTenant();
        $headers = $this->headersFor($other['user']);
        $leaks = [];

        foreach ($this->endpoints($stay, $folio, $line, $task, $exception) as [$method, $uri, $body]) {
            $status = $this->json($method, '/api/v1/'.$uri, $body, $headers)->getStatusCode();

            // 403: the module is not active for that tenant; 404 or 422: the id does not exist for it.
            if (! in_array($status, [403, 404, 422], true)) {
                $leaks[] = "{$method} {$uri} answered {$status}";
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_guest_identity_is_masked_audited_when_revealed_and_hidden_by_field_rules(): void
    {
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $admin = $this->userWith('admin', Scope::company($this->acme->id));

        $masked = $this->getJson("/api/v1/hotel/guests/{$this->guest->id}", $this->headersFor($receptionist))->assertOk()->json('data');
        $this->assertSame('•••• 5678', $masked['identity_document']);
        $this->assertStringNotContainsString('12345678', json_encode($masked));

        $this->getJson("/api/v1/hotel/guests/{$this->guest->id}?reveal=1", $this->headersFor($receptionist))->assertOk()->assertJsonPath('data.identity_document', '12345678');
        $this->assertSame(1, $this->audited('hotel.guest.identity_view'));
        // The Admin configures and reads but holds no identity permission.
        $this->getJson("/api/v1/hotel/guests/{$this->guest->id}?reveal=1", $this->headersFor($admin))->assertForbidden();
        $this->getJson("/api/v1/hotel/guests/{$this->guest->id}", $this->headersFor($admin))->assertOk()->assertJsonPath('data.identity_document', '•••• 5678')->assertJsonPath('data.date_of_birth', null);
        $this->patchJson("/api/v1/hotel/guests/{$this->guest->id}", ['id_number' => 'X'], $this->headersFor($admin))->assertForbidden();

        // A field rule (RBAC-05) that hides the document leaves it out entirely, and refuses writes to it.
        $role = $this->inTenant(fn () => $this->roles->get('receptionist'));
        $copy = $this->inTenant(function () {
            $custom = $this->role('Desk without ID', ['hotel.guest.view', 'hotel.guest.manage', 'hotel.guest.identity']);
            FieldRule::create(['role_id' => $custom->id, 'resource' => 'hotel_guest', 'field' => 'identity_document', 'mode' => 'hidden']);

            return $custom;
        });
        $user = $this->inTenant(function () use ($copy) {
            $u = $this->colleague($this->owner);
            $this->assign($u, $copy, Scope::company($this->acme->id));

            return $u;
        });
        $shown = $this->getJson("/api/v1/hotel/guests/{$this->guest->id}", $this->headersFor($user))->assertOk()->json('data');
        $this->assertArrayNotHasKey('identity_document', $shown);
        $this->patchJson("/api/v1/hotel/guests/{$this->guest->id}", ['id_number' => 'Y1'], $this->headersFor($user))->assertStatus(422)->assertJsonPath('code', 'field_readonly');
        $this->assertNotNull($role);
    }
}
