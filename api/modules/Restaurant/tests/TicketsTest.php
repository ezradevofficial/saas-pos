<?php

namespace Modules\Restaurant\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-09, REST-12, RBAC-04: the kitchen display's feed and a ticket's
// status, for the kitchen and bar staff at their location, and the waiter's
// "served" from the till.
class TicketsTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    private function sendOne(): array
    {
        [$cheese] = $this->modifier('Extra cheese');
        $record = $this->orderRecord([
            $this->sent($this->orderLine(['note' => 'no onions', 'modifiers' => [['id' => $cheese, 'name' => 'Extra cheese', 'price_minor' => '5000']]])),
            $this->sent($this->orderLine(['item_id' => $this->beer->id, 'name' => 'Beer', 'qty' => '2', 'unit_price_minor' => '35000'])),
        ]);
        $this->store($record);

        return $record;
    }

    public function test_the_display_lists_active_tickets_per_station_with_their_lines(): void
    {
        $this->sendOne();
        $url = "restaurant/tickets?location_id={$this->locationA->id}";

        $all = $this->api('GET', $url, [], $this->cook)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $kitchen = collect($all)->firstWhere('station_name', 'Kitchen');
        $this->assertSame(['new', 'order', 1, 'T1', 2], [$kitchen['status'], $kitchen['kind'], $kitchen['number'], $kitchen['table_name'], $kitchen['covers']]);
        $this->assertSame(['name' => 'Burger', 'qty' => '1', 'note' => 'no onions', 'modifiers' => [['name' => 'Extra cheese']]], [
            'name' => $kitchen['lines'][0]['name'], 'qty' => $kitchen['lines'][0]['qty'], 'note' => $kitchen['lines'][0]['note'], 'modifiers' => $kitchen['lines'][0]['modifiers'],
        ]);

        $this->api('GET', "{$url}&station_id={$this->bar->id}", [], $this->cook)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lines.0.qty', '2');
        $this->assertSame($this->waiter->name, $kitchen['waiter_name']);
    }

    public function test_the_display_shows_a_tab_by_its_initials_only(): void
    {
        $tab = $this->orderRecord([$this->sent($this->orderLine(['item_id' => $this->beer->id, 'name' => 'Beer']))], ['kind' => 'tab', 'table_id' => null, 'tab_name' => 'Otieno kamau']);
        $this->store($tab);

        $this->api('GET', "restaurant/tickets?location_id={$this->locationA->id}", [], $this->cook)->assertOk()->assertJsonPath('data.0.tab_name', 'O.K.');
        $this->api('GET', "restaurant/orders/{$tab['id']}")->assertOk()->assertJsonPath('data.tab_name', 'Otieno kamau')->assertJsonPath('data.tickets.0.tab_name', 'Otieno kamau');
    }

    public function test_a_ticket_moves_through_its_statuses_and_back(): void
    {
        $this->sendOne();
        $ticket = Ticket::query()->where('station_id', $this->kitchen->id)->sole();
        $status = fn (string $to, $user = null) => $this->api('POST', "restaurant/tickets/{$ticket->id}/status", ['status' => $to], $user ?? $this->cook);

        $status('preparing')->assertOk()->assertJsonPath('data.status', 'preparing')->assertJsonStructure(['data' => ['preparing_at']]);
        $ready = $status('ready')->assertOk()->json('data');
        $this->assertNotNull($ready['ready_at']);
        $recalled = $status('preparing')->assertOk()->json('data');
        $this->assertNull($recalled['ready_at'], 'a recalled ticket is not ready any more');
        $status('ready');
        $status('served', $this->waiter)->assertForbidden();
        $served = $status('served')->assertOk()->json('data');
        $this->assertNotNull($served['served_at']);
        $status('flying')->assertUnprocessable();

        $this->assertGreaterThanOrEqual(4, AuditEntry::query()->where('action', 'restaurant.ticket.status')->count());
        // Served tickets leave the active feed but stay in the day's list.
        $url = "restaurant/tickets?location_id={$this->locationA->id}";
        $this->api('GET', $url, [], $this->cook)->assertJsonCount(1, 'data');
        $this->api('GET', "{$url}&status=all", [], $this->cook)->assertJsonCount(2, 'data');
        $this->api('GET', "{$url}&status=served", [], $this->cook)->assertJsonCount(1, 'data');
    }

    public function test_the_feed_can_be_read_from_a_moment_on(): void
    {
        $this->sendOne();
        $ticket = Ticket::query()->where('station_id', $this->kitchen->id)->sole();
        $since = urlencode(now()->addSecond()->toIso8601String());
        $this->travel(5)->seconds();
        $this->api('POST', "restaurant/tickets/{$ticket->id}/status", ['status' => 'preparing'], $this->cook)->assertOk();

        $this->api('GET', "restaurant/tickets?location_id={$this->locationA->id}&status=all&since={$since}", [], $this->cook)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'preparing');
    }

    public function test_tickets_are_for_staff_at_their_location_only(): void
    {
        $this->sendOne();
        $ticket = Ticket::query()->where('station_id', $this->kitchen->id)->sole();
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->api('GET', 'restaurant/tickets', [], $managerB)->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', "restaurant/tickets/{$ticket->id}/status", ['status' => 'ready'], $managerB)->assertForbidden();
        $this->api('GET', 'restaurant/tickets', [], $cashier)->assertForbidden();
        $this->api('POST', "restaurant/tickets/{$ticket->id}/status", ['status' => 'ready'], $cashier)->assertForbidden();
        $this->assertSame('new', Ticket::query()->find($ticket->id)->status);

        $other = $this->otherRestaurantTenant();
        $this->json('POST', "/api/v1/restaurant/tickets/{$ticket->id}/status", ['status' => 'ready'], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_a_till_marks_served_for_a_person_who_may(): void
    {
        $this->sendOne();
        $ticket = Ticket::query()->where('station_id', $this->kitchen->id)->sole();
        $url = "/api/v1/restaurant/device/tickets/{$ticket->id}/status";

        $this->postJson($url, ['status' => 'served', 'user_id' => $this->waiter->id], $this->tillHeaders())->assertForbidden()->assertJsonPath('code', 'ticket_not_permitted');
        $this->postJson($url, ['status' => 'served', 'user_id' => $this->cook->id], $this->tillHeaders())->assertOk()->assertJsonPath('data.status', 'served');

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->postJson($url, ['status' => 'new', 'user_id' => $cashier->id], $this->tillHeaders())->assertForbidden()->assertJsonPath('code', 'ticket_not_permitted');
        $this->postJson($url, ['status' => 'new', 'user_id' => $this->id()], $this->tillHeaders())->assertUnprocessable()->assertJsonPath('code', 'user_unknown');
        $this->postJson($url, ['status' => 'served'], $this->tillHeaders())->assertUnprocessable();
        $this->postJson($url, ['status' => 'served', 'user_id' => $this->waiter->id], $this->headersFor())->assertForbidden();

        // A till of another location cannot reach the ticket.
        [, $token] = $this->pairedTill($this->locationB, 'Till B');
        $this->postJson($url, ['status' => 'new', 'user_id' => $this->waiter->id], $this->tillHeaders($token))->assertNotFound();
    }
}
