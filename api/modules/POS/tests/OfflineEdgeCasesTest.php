<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Prices\ItemPrice;
use Modules\POS\Models\NumberRange;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\Shift;
use Modules\POS\Models\ShiftBalance;
use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\POS\Tests\Concerns\SimulatesDevice;
use Modules\POS\Tests\Support\FakeOverrides;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04, NUM-02, POS-04, POS-09 (ADR 004, phase 4 Task 7): what a till
// meets when it comes back online, through the device endpoints:
// duplicate uploads, conflicts (server wins on master data, device wins on
// completed sales), number range exhaustion and the next range, and a
// backlog in another order than the engine's, shifts flagged rather than
// refused, and sales whose shift never arrives. Racing duplicates are in
// ConcurrencyTest (real concurrent sessions).
class OfflineEdgeCasesTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase, SimulatesDevice;

    private array $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->device = ['id' => $this->till->id, 'token' => $this->tillToken];
    }

    private function shifts(array $shifts)
    {
        return $this->postJson('/api/v1/pos/shifts', ['shifts' => $shifts], $this->tillHeaders());
    }

    private function closing(array $counted): array
    {
        return ['closed_by_id' => $this->owner->id, 'closed_at' => now()->toIso8601String(), 'counted' => $counted, 'note' => null];
    }

    // -- b. Duplicate uploads ---------------------------------------------------

    public function test_a_sale_uploaded_twice_or_twice_in_one_batch_is_stored_once_with_the_same_answer(): void
    {
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);

        $first = $this->upload([$sale])->assertOk()->json('results.0');
        // The answer was lost: the till sends it again, and again inside one batch with a new sale.
        $second = $this->upload([$sale])->assertOk()->json('results.0');
        $batch = $this->upload([$sale, $this->saleBody($shift, 2), $sale])->assertOk()->json('results');

        $this->assertSame($first, $second);
        $this->assertSame([$first, $first], [$batch[0], $batch[2]]);
        $this->assertSame('stored', $batch[1]['status']);
        $this->inTenant(fn () => $this->assertSame(2, Sale::count()));
    }

    public function test_a_resent_sale_with_a_changed_body_is_refused_and_the_stored_sale_is_kept(): void
    {
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);
        $this->upload([$sale])->assertOk();

        $changed = $sale;
        $changed['lines'][0]['qty'] = '1';
        $changed['lines'][0]['tax_minor'] = '6250';
        $changed['lines'][0]['total_minor'] = $changed['payments'][0]['amount_minor'] = $changed['payments'][0]['amount_in_sale_minor'] = '56250';
        $changed['totals'] = ['subtotal_minor' => '56250', 'discount_minor' => '0', 'tax_minor' => '6250', 'total_minor' => '56250'];

        $this->upload([$changed])->assertUnprocessable()
            ->assertJsonPath('code', 'upload_rejected')
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.error.code', 'payload_mismatch')
            ->assertJsonPath('results.0.error.retryable', false);

        $this->inTenant(function () use ($sale) {
            $this->assertSame('112500', (string) Sale::findOrFail($sale['id'])->total_minor);
            $this->assertSame('2.000000', SaleLine::findOrFail($sale['lines'][0]['id'])->qty);
        });
        // The original is still answered as stored.
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
    }

    // -- c. Conflicts -------------------------------------------------------------

    public function test_the_server_wins_on_master_data_and_the_device_wins_on_completed_sales(): void
    {
        $this->ranges()->assertOk();
        $pulled = $this->pullAllEntities($this->device, ['items', 'item_prices', 'payment_methods']);
        $copy = array_map(fn (array $pages) => $this->applyPages([], $pages), $pulled['pages']);
        $this->assertSame('56250', collect($copy['item_prices'])->firstWhere('item_id', $this->soap->id)['amount_minor']);

        // Offline, the till sells at its copy's price; meanwhile the back office edits the same records.
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1, ['offline' => true]);
        $this->inTenant(function () {
            $this->soap->forceFill(['name' => 'Soap 200 g'])->save();
            ItemPrice::where('item_id', $this->soap->id)->sole()->forceFill(['amount_minor' => '60750'])->save();
            $this->methods['mpesa']->forceFill(['name' => 'M-Pesa till'])->save();
        });

        // Device wins: the sale is kept at KES 562.50 as sold, the difference flagged.
        $this->upload([$sale])->assertOk()
            ->assertJsonPath('results.0.status', 'stored')
            ->assertJsonPath('results.0.flags.0.code', 'price_differs')
            ->assertJsonPath('results.0.flags.0.detail.expected_unit_price_minor', '60750');
        $this->inTenant(fn () => $this->assertSame(['56250', '112500'], [
            (string) SaleLine::findOrFail($sale['lines'][0]['id'])->unit_price_minor, (string) Sale::findOrFail($sale['id'])->total_minor,
        ]));

        // Server wins: the next pull overwrites the device's copy with the server's records.
        $after = $this->pullAllEntities($this->device, ['items', 'item_prices', 'payment_methods'], $pulled['cursors']);
        foreach ($after['pages'] as $key => $pages) {
            $copy[$key] = $this->applyPages($copy[$key], $pages);
        }
        $this->assertSame('Soap 200 g', $copy['items'][$this->soap->id]['name']);
        $this->assertSame('60750', collect($copy['item_prices'])->firstWhere('item_id', $this->soap->id)['amount_minor']);
        $this->assertSame('M-Pesa till', $copy['payment_methods'][$this->methods['mpesa']->id]['name']);
        $this->assertTrue($after['pages']['payment_methods'][0]['replace']);
    }

    // -- d. Range exhaustion and the next range -----------------------------------

    public function test_an_exhausted_range_is_topped_up_below_the_threshold_and_its_numbers_stay_valid(): void
    {
        config(['pos.ranges.size' => 10, 'pos.ranges.threshold' => 3]);
        $first = $this->ranges()->assertOk()->json('data.0');
        $this->assertSame([1, 10], [$first['from'], $first['to']]);
        // Another till takes the next block meanwhile: ranges never overlap.
        [, $other] = $this->pairedTill($this->locationA, 'Till 2');
        $this->ranges(token: $other)->assertOk()->assertJsonPath('data.0.from', 11);

        // Offline the till uses all ten numbers; it may not invent an eleventh.
        $shift = $this->openShift();
        $sales = array_map(fn (int $seq) => $this->saleBody($shift, $seq), range(1, 10));
        $beyond = $this->saleBody($shift, 11);

        // 7 used, 3 left: not below the threshold, no new block. 8 used, 2 left: the next block.
        $this->ranges(next: 8)->assertOk()->assertJsonCount(1, 'data');
        $this->upload(array_slice($sales, 0, 8))->assertOk();
        $next = $this->ranges(next: 9)->assertOk()->assertJsonCount(2, 'data')->json('data.1');
        $this->assertSame([21, 30], [$next['from'], $next['to']]);

        // The rest of the first block, the 11th (never given to this till) refused, then the new block.
        $this->upload([...array_slice($sales, 8), $beyond])->assertOk()
            ->assertJsonPath('results.0.status', 'stored')
            ->assertJsonPath('results.1.status', 'stored')
            ->assertJsonPath('results.2.error.code', 'receipt_range_unknown')
            ->assertJsonPath('results.2.error.retryable', false);
        $this->upload([$this->saleBody($shift, 21, ['receipt_number' => 'R-L01-000021'])])->assertOk();

        $this->inTenant(function () use ($first) {
            $this->assertSame(NumberRange::EXHAUSTED, NumberRange::findOrFail($first['id'])->status);
            $this->assertSame(11, Sale::count());
        });
        // Exhausted, the first block is no longer listed; a sale numbered from it still uploads late.
        $this->ranges(next: 22)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.from', 21);
        $this->inTenant(fn () => $this->assertSame($first['id'], Sale::where('receipt_seq', 10)->sole()->number_range_id));
    }

    // -- e. Backlog ordering ------------------------------------------------------

    public function test_records_before_what_they_name_are_refused_as_retryable_and_stored_once_it_arrives(): void
    {
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $shift = $this->shiftBody();
        $sale = $this->saleBody($shift['id'], 1);
        $void = ['id' => $this->id(), 'actor_proof' => FakeOverrides::ATTESTED, 'sale_id' => $sale['id'], 'voided_by_id' => $this->owner->id, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong item'];
        $movement = ['id' => $this->id(), 'actor_proof' => FakeOverrides::ATTESTED, 'shift_id' => $shift['id'], 'user_id' => $this->owner->id, 'kind' => 'pay_in', 'currency' => 'KES', 'amount_minor' => '10000', 'reason' => 'Float', 'occurred_at' => now()->toIso8601String()];

        // Effects before causes: each waits, retryable, and nothing is stored.
        $this->upload([$sale])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'shift_unknown')->assertJsonPath('results.0.error.retryable', true);
        $this->postJson('/api/v1/pos/cash-movements', ['movements' => [$movement]], $this->tillHeaders())->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'shift_unknown')->assertJsonPath('results.0.error.retryable', true);
        $this->postJson('/api/v1/pos/voids', ['voids' => [$void]], $this->tillHeaders())->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'sale_unknown')->assertJsonPath('results.0.error.retryable', true);
        $this->inTenant(fn () => $this->assertSame(0, Sale::count()));

        // Once the cause is there, the same records go through.
        $this->shifts([$shift])->assertOk();
        $this->upload([$sale])->assertOk();
        $this->postJson('/api/v1/pos/cash-movements', ['movements' => [$movement]], $this->tillHeaders())->assertOk();
        $this->postJson('/api/v1/pos/voids', ['voids' => [$void]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.void_status', 'applied');
        $this->inTenant(fn () => $this->assertSame(Sale::VOIDED, Sale::findOrFail($sale['id'])->status));
    }

    public function test_sales_out_of_order_are_all_stored_and_the_range_moves_to_the_highest(): void
    {
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sales = array_map(fn (int $seq) => $this->saleBody($shift, $seq, ['sold_at' => now()->subMinutes(60 - $seq)->toIso8601String()]), range(1, 6));

        $this->upload([$sales[4], $sales[0], $sales[5]])->assertOk();
        $this->upload([$sales[2], $sales[1], $sales[3]])->assertOk();

        $this->inTenant(function () {
            $this->assertSame([1, 2, 3, 4, 5, 6], Sale::orderBy('receipt_seq')->pluck('receipt_seq')->all());
            $this->assertSame(7, NumberRange::sole()->next_value);
            $this->assertSame([], Sale::whereRaw("flags::text <> '[]'")->pluck('receipt_number')->all());
        });
    }

    public function test_two_shifts_sent_open_before_either_closes_wait_for_the_first_close(): void
    {
        // The literal order "shifts, then the rest, then shifts again to close" with a two-day backlog.
        $day1 = $this->shiftBody();
        $day2 = $this->shiftBody();

        $this->shifts([$day1, $day2])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'open')
            ->assertJsonPath('results.1.error.code', 'shift_already_open')
            ->assertJsonPath('results.1.error.retryable', true);

        // The close of day 1 lets day 2 open on the retry, in the same batch.
        $this->shifts([[...$day1, 'closing' => $this->closing([['currency' => 'KES', 'amount_minor' => '500000']])], $day2])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'closed')
            ->assertJsonPath('results.1.shift_status', 'open');
    }

    public function test_a_shift_sent_closed_before_its_sales_flags_them_and_recounts_the_cash(): void
    {
        $this->ranges()->assertOk();
        // The till sends the shift already closed (it counted KES 6,125.00), then its sales.
        $shift = [...$this->shiftBody(), 'closing' => $this->closing([['currency' => 'KES', 'amount_minor' => '612500']])];
        $this->shifts([$shift])->assertOk()->assertJsonPath('results.0.shift_status', 'closed');
        $this->inTenant(fn () => $this->assertSame('112500', (string) ShiftBalance::where('shift_id', $shift['id'])->sole()->variance_minor));

        $this->upload([$this->saleBody($shift['id'], 1)])->assertOk()->assertJsonPath('results.0.flags.0.code', 'received_after_close');

        // H4: the late sale is counted: expected 5,000.00 + 1,125.00, no variance left.
        $this->inTenant(function () use ($shift) {
            $balance = ShiftBalance::where('shift_id', $shift['id'])->sole();
            $this->assertSame(['612500', '612500', '0'], [(string) $balance->expected_minor, (string) $balance->counted_minor, (string) $balance->variance_minor]);
            $this->assertSame(Shift::CLOSED, Shift::findOrFail($shift['id'])->status);
        });
    }

    /**
     * Was the gap "sales of a refused shift retry for ever". A shift is no
     * longer refused for its opener's permission: it is stored and flagged
     * `opener_not_permitted`, so its sales land on it (POS-04, NFR-04).
     */
    public function test_a_shift_opened_by_someone_without_the_permission_is_kept_flagged_and_its_sales_land(): void
    {
        $this->ranges()->assertOk();
        $stranger = $this->inTenant(fn () => $this->colleague($this->owner));
        $shift = $this->shiftBody(['opened_by_id' => $stranger->id]);

        $this->shifts([$shift])->assertOk()
            ->assertJsonPath('results.0.status', 'stored')
            ->assertJsonPath('results.0.flags.0.code', 'opener_not_permitted');
        $this->upload([$this->saleBody($shift['id'], 1)])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(function () use ($shift) {
            $this->assertSame(1, Sale::where('shift_id', $shift['id'])->count());
            $this->assertSame('opener_not_permitted', Shift::findOrFail($shift['id'])->flags[0]['code']);
        });
        // The back office sees the shift's flags.
        $this->getJson("/api/v1/pos/shifts/{$shift['id']}", $this->headersFor())->assertOk()->assertJsonPath('data.flags.0.code', 'opener_not_permitted');
    }

    /**
     * NFR-04: a sale naming a shift the server never received waits
     * (`shift_unknown`, retryable) for `pos.unknown_shift_grace_hours`
     * after it was sold; after that it is stored on a closed placeholder
     * shift with the device's shift id (flagged `placeholder`), flagged
     * `shift_missing`, and waits in the flagged-sale review (M3).
     */
    public function test_a_sale_whose_shift_never_arrives_is_stored_on_a_placeholder_after_the_grace(): void
    {
        $this->ranges()->assertOk();
        $missing = $this->id();
        $recent = $this->saleBody($missing, 1, ['sold_at' => now()->subHours(71)->toIso8601String()]);
        $old = $this->saleBody($missing, 2, ['sold_at' => now()->subHours(73)->toIso8601String()]);

        // Inside the grace: still waiting, nothing stored.
        $this->upload([$recent])->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'shift_unknown')
            ->assertJsonPath('results.0.error.retryable', true);

        // Past it: stored, flagged, on a placeholder; a resend answers the same.
        $first = $this->upload([$old])->assertOk()
            ->assertJsonPath('results.0.status', 'stored')
            ->assertJsonPath('results.0.flags', [['code' => 'shift_missing']])
            ->json('results.0');
        $this->assertSame($first, $this->upload([$old])->assertOk()->json('results.0'));

        // The placeholder now exists, so the sale still inside the grace lands on it too, flagged.
        $this->upload([$recent])->assertOk()->assertJsonPath('results.0.flags', [['code' => 'shift_missing']]);

        $this->inTenant(function () use ($missing, $old) {
            $placeholder = Shift::findOrFail($missing);
            $this->assertSame([Shift::CLOSED, $this->till->id, [['code' => 'placeholder']]], [$placeholder->status, $placeholder->device_id, $placeholder->flags]);
            $this->assertSame($this->owner->id, $placeholder->opened_by);
            $this->assertSame(2, Sale::where('shift_id', $missing)->count());
            $this->assertSame(1, AuditEntry::where('action', 'pos.shift.placeholder')->count());
            // H4: the placeholder's expected cash follows its sales (nothing counted).
            $this->assertSame('225000', (string) ShiftBalance::where('shift_id', $missing)->where('currency', 'KES')->sole()->expected_minor);
            $this->assertNull(Sale::findOrFail($old['id'])->reviewed_at);
        });

        // Back-office review: the sale waits among flagged, unreviewed sales and is acknowledged there.
        $this->getJson('/api/v1/pos/sales?flag=shift_missing&reviewed=0', $this->headersFor())->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/pos/sales/{$old['id']}/review", ['note' => 'Shift lost on the till'], $this->headersFor())->assertOk();
        $this->getJson('/api/v1/pos/sales?flag=shift_missing&reviewed=0', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');

        // The real shift arriving later is answered as stored (the placeholder stands; its float is left to review).
        $this->shifts([$this->shiftBody(['id' => $missing])])->assertOk()
            ->assertJsonPath('results.0.shift_status', 'closed')
            ->assertJsonPath('results.0.flags.0.code', 'placeholder');
    }

    public function test_the_grace_is_configurable(): void
    {
        config(['pos.unknown_shift_grace_hours' => 1]);
        $this->ranges()->assertOk();

        $this->upload([$this->saleBody($this->id(), 1, ['sold_at' => now()->subHours(2)->toIso8601String()])])->assertOk()
            ->assertJsonPath('results.0.flags.0.code', 'shift_missing');
    }
}
