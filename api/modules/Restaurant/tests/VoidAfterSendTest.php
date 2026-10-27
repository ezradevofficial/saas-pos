<?php

namespace Modules\Restaurant\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\LimitRule;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Models\TicketLine;
use Modules\Restaurant\Tests\Concerns\BuildsRestaurant;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// REST-11, RBAC-06, AUTH-08, AUD-01: an unsent line is removed freely; a sent
// one needs pos.sale.void within the person's limit or a manager's override,
// a reason, and tells the station with a void ticket. What cannot be proven
// is applied and flagged; everything is audited.
class VoidAfterSendTest extends TestCase
{
    use BuildsRestaurant, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    /** A table with one sent line and one unsent line; returns [record, sentLine, unsentLine]. */
    private function table(): array
    {
        $sent = $this->sent($this->orderLine(['name' => 'Steak']));
        $unsent = $this->orderLine(['name' => 'Soup']);
        $record = $this->orderRecord([$sent, $unsent]);
        $this->store($record);

        return [$record, $sent, $unsent];
    }

    private function void(array $record, array $line, array $removed): array
    {
        $record['lines'] = [array_replace($line, ['removed' => $removed])];

        return $this->sync([$record])->json('results.0.lines.0');
    }

    public function test_an_unsent_line_is_removed_freely_by_a_waiter(): void
    {
        [$record, , $unsent] = $this->table();

        $result = $this->void($record, $unsent, $this->removed($this->waiter, 'Changed mind'));

        $this->assertSame('stored', $result['status']);
        $line = OrderLine::query()->find($unsent['id']);
        $this->assertNotNull($line->voided_at);
        $this->assertFalse((bool) $line->void_after_send);
        $this->assertSame(0, Ticket::query()->where('kind', 'void')->count(), 'nothing was sent, so the station is not told');
    }

    public function test_a_waiter_cannot_void_a_sent_line_alone(): void
    {
        [$record, $sent] = $this->table();

        $result = $this->void($record, $sent, $this->removed($this->waiter));

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('override_required', $result['error']['code']);
        $this->assertNull(OrderLine::query()->find($sent['id'])->voided_at);
    }

    public function test_a_managers_override_voids_a_sent_line_and_tells_the_station(): void
    {
        [$record, $sent] = $this->table();

        $result = $this->void($record, $sent, $this->removed($this->waiter, 'Guest left', ['override' => $this->override($this->manager->id)]));

        $this->assertSame('stored', $result['status']);
        $line = OrderLine::query()->find($sent['id']);
        $this->assertTrue((bool) $line->void_after_send);
        $this->assertSame($this->waiter->id, $line->voided_by);
        $this->assertSame($this->manager->id, $line->void_approved_by);
        $this->assertSame('Guest left', $line->void_reason);

        $ticket = Ticket::query()->where('kind', 'void')->sole();
        $this->assertSame($this->kitchen->id, $ticket->station_id);
        $this->assertTrue((bool) TicketLine::query()->where('ticket_id', $ticket->id)->sole()->void);

        $entry = AuditEntry::query()->where('action', 'restaurant.order.void_sent')->sole();
        $this->assertSame($this->manager->id, $entry->on_behalf_of_user_id);
        $this->assertSame('Guest left', $entry->after['reason']);
    }

    public function test_a_manager_within_their_limit_voids_with_a_proven_sign_in(): void
    {
        [$record, $sent] = $this->table();

        $result = $this->void($record, $sent, $this->removed($this->manager, extra: ['actor_proof' => $this->actorProof($this->manager->id, online: true)]));

        $this->assertSame('stored', $result['status']);
        $line = OrderLine::query()->find($sent['id']);
        $this->assertNull($line->void_approved_by);
        $this->assertSame([], $line->flags);
    }

    public function test_what_cannot_be_proven_waits_for_review_and_stays_on_the_bill(): void
    {
        [$record, $sent] = $this->table();

        $result = $this->void($record, $sent, $this->removed($this->manager, extra: ['actor_proof' => null]));

        $this->assertSame(['stored', 'pending'], [$result['status'], $result['void']]);
        $line = OrderLine::query()->find($sent['id']);
        $this->assertNull($line->voided_at, 'the line stays on the bill');
        $this->assertSame($this->manager->id, $line->pending_void['by']);
        $this->assertEqualsCanonicalizing(['actor_unverified', 'void_pending'], array_column($line->flags, 'code'));
        $this->assertSame(0, Ticket::query()->where('kind', 'void')->count(), 'the station is not told yet');
        $this->api('GET', "restaurant/orders/{$record['id']}")->assertOk()->assertJsonPath('data.lines.0.void_pending.by', $this->manager->id)->assertJsonPath('data.lines.0.void', null);
    }

    public function test_a_void_above_the_persons_limit_needs_a_manager_with_a_higher_one(): void
    {
        // The burger is KES 900.00 with tax: above a limit of KES 500.
        $this->inTenant(fn () => LimitRule::where('role_id', $this->roles->get('branch_manager')->id)->where('key', 'max_refund_amount')->update(['value' => '500']));
        [$record, $sent] = $this->table();

        $result = $this->void($record, $sent, $this->removed($this->manager));
        $this->assertSame('void_limit_exceeded', $result['error']['code']);

        // The Owner has no limits.
        $result = $this->void($record, $sent, $this->removed($this->owner, extra: ['actor_proof' => $this->actorProof($this->owner->id, online: true)]));
        $this->assertSame('stored', $result['status']);
    }

    public function test_a_sent_line_needs_a_reason(): void
    {
        [$record, $sent] = $this->table();

        $result = $this->void($record, $sent, $this->removed($this->manager, reason: ''));

        $this->assertSame('reason_required', $result['error']['code']);
    }

    public function test_a_void_is_idempotent(): void
    {
        [$record, $sent] = $this->table();
        $removed = $this->removed($this->manager, extra: ['actor_proof' => $this->actorProof($this->manager->id, online: true)]);

        $this->void($record, $sent, $removed);
        $this->void($record, $sent, $removed);

        $this->assertSame(1, Ticket::query()->where('kind', 'void')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'restaurant.order.void_sent')->count());
    }

    public function test_the_back_office_void_follows_the_same_rules(): void
    {
        [$record, $sent, $unsent] = $this->table();
        $url = fn (array $line) => "restaurant/orders/{$record['id']}/lines/{$line['id']}/void";

        // A waiter may drop an unsent line, not a sent one.
        $this->api('POST', $url($unsent), ['reason' => 'Changed mind'], $this->waiter)->assertOk()->assertJsonPath('data.lines.1.void.after_send', false);
        $this->api('POST', $url($sent), ['reason' => 'Guest left'], $this->waiter)->assertForbidden()->assertJsonPath('code', 'void_not_permitted');
        $this->api('POST', $url($sent), [], $this->manager)->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->api('POST', $url($sent), ['reason' => 'Guest left'], $this->manager)->assertOk()
            ->assertJsonPath('data.lines.0.void.after_send', true)->assertJsonPath('data.bill.lines_minor', '0');
        $this->assertSame(1, Ticket::query()->where('kind', 'void')->count());

        // The kitchen user cannot even edit orders; another tenant's order is not found.
        $this->api('POST', $url($sent), ['reason' => 'x'], $this->cook)->assertForbidden();
        $other = $this->otherTenant();
        $this->json('POST', '/api/v1/'.$url($sent), ['reason' => 'x'], $this->headersFor($other['user']))->assertNotFound();
    }
}
