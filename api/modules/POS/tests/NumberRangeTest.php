<?php

namespace Modules\POS\Tests;

use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\ModuleRegistry;
use Modules\POS\Models\NumberRange;
use Modules\POS\Sync\NumberRanges;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NUM-02: devices get blocks of the receipt counter (NUM-01) when few
// numbers remain; blocks never overlap; a lost device's ranges are retired
// on unpair; a yearly format's old-year ranges are retired.
class NumberRangeTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        config(['pos.ranges.size' => 10, 'pos.ranges.threshold' => 3]);
    }

    public function test_a_device_gets_a_block_and_the_next_one_only_when_few_numbers_remain(): void
    {
        $this->ranges()->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.from', 1)
            ->assertJsonPath('data.0.to', 10)
            ->assertJsonPath('data.0.next', 1)
            ->assertJsonPath('data.0.pattern', 'R-L01-{000001}')
            ->assertJsonPath('data.0.period', 'all');

        // 7 of 10 used: 4 left, above the threshold of 3.
        $this->ranges(next: 7)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.next', 7);

        // 9 used: 2 left, so a second block comes; the next device's block follows it.
        $this->ranges(next: 10)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.from', 11)->assertJsonPath('data.1.to', 20);
        [, $other] = $this->pairedTill($this->locationB, 'Till B');
        $this->ranges(token: $other)->assertOk()->assertJsonPath('data.0.from', 21)->assertJsonPath('data.0.pattern', 'R-L02-{000001}');

        // The first block is spent: it is exhausted and no longer listed.
        $this->ranges(next: 11)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.from', 11);
        $this->inTenant(fn () => $this->assertSame(NumberRange::EXHAUSTED, NumberRange::where('range_from', 1)->sole()->status));
    }

    public function test_a_pull_right_after_a_top_up_or_a_shift_change_sees_it_despite_the_snapshot_cache(): void
    {
        // NFR-05: snapshots are cached, but numbers and the open shift must reach the till at once.
        config(['sync.snapshot_ttl_seconds' => 30]);
        $pull = fn (string $entity) => $this->getJson('/api/v1/sync/pull?'.http_build_query(['entities' => [$entity]]), $this->tillHeaders())
            ->assertOk()->json("entities.{$entity}.upserts");

        $this->ranges()->assertOk();
        $this->assertSame([1], array_column($pull('pos_number_ranges'), 'from'));
        $this->assertSame([], $pull('pos_open_shift'));

        // A top-up allocates the next block: the next pull has it.
        $this->ranges(next: 9)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([1, 11], array_column($pull('pos_number_ranges'), 'from'));

        // An unpaired-device style retirement is seen at once too.
        $this->inTenant(fn () => app(NumberRanges::class)->retire($this->till->id));
        $this->assertSame([], $pull('pos_number_ranges'));

        // A shift opened, then closed.
        $shift = $this->openShift();
        $this->assertSame([$shift], array_column($pull('pos_open_shift'), 'id'));
        $closing = ['closed_by_id' => $this->owner->id, 'closed_at' => now()->toIso8601String(), 'counted' => [['currency' => 'KES', 'amount_minor' => '500000']]];
        $this->postJson('/api/v1/pos/shifts', ['shifts' => [$this->shiftBody(['id' => $shift, 'closing' => $closing])]], $this->tillHeaders())->assertOk();
        $this->assertSame([], $pull('pos_open_shift'));
    }

    public function test_refund_receipts_have_their_own_counter(): void
    {
        $this->ranges()->assertOk()->assertJsonPath('data.0.from', 1);
        $this->ranges('pos.refund')->assertOk()->assertJsonPath('data.0.from', 1)->assertJsonPath('data.0.pattern', 'RF-L01-{000001}');
        $this->ranges('pos.other')->assertUnprocessable()->assertJsonValidationErrors('document_type');
    }

    public function test_unpairing_a_lost_device_retires_its_ranges(): void
    {
        $this->ranges()->assertOk();

        $this->postJson("/api/v1/devices/{$this->till->id}/unpair", [], $this->headersFor())->assertOk();

        $this->inTenant(fn () => $this->assertSame([NumberRange::RETIRED], NumberRange::pluck('status')->all()));
        // Its token no longer works.
        $this->ranges()->assertUnauthorized();
    }

    public function test_a_yearly_format_gives_ranges_per_year_and_retires_the_old_years(): void
    {
        $this->inTenant(fn () => NumberFormat::create(['document_type' => 'pos.receipt', 'pattern' => 'R-{LOCATION}-{YY}-{0001}', 'reset' => 'yearly']));
        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(12, 0));

        // Within 14 days of the year's end the till also gets next year's range, ahead of time.
        $this->ranges()->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.period', '2026')->assertJsonPath('data.0.pattern', 'R-L01-26-{0001}')
            ->assertJsonPath('data.1.period', '2027')->assertJsonPath('data.1.pattern', 'R-L01-27-{0001}');

        $this->travelTo(now()->setDate(2027, 1, 2)->setTime(9, 0));
        $this->ranges()->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period', '2027')
            ->assertJsonPath('data.0.from', 1)
            ->assertJsonPath('data.0.pattern', 'R-L01-27-{0001}');
        $this->inTenant(fn () => $this->assertSame(NumberRange::RETIRED, NumberRange::where('period', '2026')->sole()->status));
    }

    public function test_a_till_offline_over_new_year_numbers_from_next_years_range_given_in_december(): void
    {
        $this->inTenant(fn () => NumberFormat::create(['document_type' => 'pos.receipt', 'pattern' => 'R-{LOCATION}-{YY}-{0001}', 'reset' => 'yearly']));

        // Early December: only this year's range.
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(12, 0));
        $this->ranges()->assertOk()->assertJsonCount(1, 'data');

        // Two weeks before the year ends, reporting this year's next number: next year's range is
        // added and is not marked used by this year's number (its counter starts again at 1).
        $this->travelTo(now()->setDate(2026, 12, 20)->setTime(12, 0));
        $this->ranges(next: 5)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.next', 5)
            ->assertJsonPath('data.1.period', '2027')->assertJsonPath('data.1.next', 1);
        // Asking again does not add a second one.
        $this->ranges(next: 6)->assertOk()->assertJsonCount(2, 'data');

        // Offline over New Year: a sale of 1 January numbered from that range is accepted.
        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(10, 0));
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1, ['receipt_number' => 'R-L01-27-0001', 'sold_at' => now()->toIso8601String()]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
    }

    public function test_only_the_device_of_a_tenant_with_the_module_gets_ranges(): void
    {
        $this->postJson('/api/v1/pos/number-ranges', ['document_type' => 'pos.receipt'], $this->headersFor())->assertForbidden();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->ranges()->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
