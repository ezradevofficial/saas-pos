<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\FixedAssets\Events\DepreciationPosted;
use Modules\FixedAssets\Events\DepreciationReversed;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\DepreciationLine;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-01, FA-07 to FA-10, FA-12, FA-13, FA-22, FA-26: monthly runs through
// the API: preview, post (one source per branch and cost centre), run
// idempotency and order, the closed-period refusal, reversal, the
// conventions, units of production from readings and the schedule.
class RunsTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets(accounting: true);
    }

    private function postRun(string $month, ?User $as = null)
    {
        return $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => $month], $as ?? $this->accountant);
    }

    public function test_a_run_charges_each_asset_and_posts_one_source_per_branch_and_cost_centre(): void
    {
        Event::fake([DepreciationPosted::class]);
        // KES 1 200 000.00 from 2026-01-01, 60 months, 10 % residual: 1 080 000.00 by days over 1 826 days.
        $van = $this->active(['cost_centre_id' => $this->operations->id]);
        $truck = $this->active(['name' => 'Truck', 'location_id' => $this->locationB->id]);
        $land = $this->active(['name' => 'Plot', 'category_id' => $this->land->id, 'cost_minor' => 500000000]);

        $preview = $this->api('GET', "runs/preview?company={$this->acme->id}&period=2026-01", [], $this->accountant)->assertOk()->json('data');
        $january = intdiv(108000000 * 31 * 2 + 1826, 1826 * 2);
        $this->assertSame(2, $preview['assets_count']);
        $this->assertSame((string) ($january * 2), $preview['total']['amount_minor']);
        $this->assertSame(['2026-01-01', '2026-01-31', '31'], [$preview['lines'][0]['from'], $preview['lines'][0]['to'], $preview['lines'][0]['units']]);
        $this->assertSame(0, $this->inTenant(fn () => DepreciationLine::query()->count()), 'a preview saves nothing');

        $run = $this->postRun('2026-01')->assertCreated()->json('data');
        $this->assertSame(['2026-01', 'posted', 2], [$run['period'], $run['status'], $run['assets_count']]);
        $this->api('GET', "assets/{$van['id']}")->assertJsonPath('data.accumulated.amount_minor', (string) $january)->assertJsonPath('data.depreciated_to', '2026-01-31');
        $this->api('GET', "assets/{$land['id']}")->assertJsonPath('data.accumulated.amount_minor', '0');

        // One source per branch and cost centre, keyed by category.
        Event::assertDispatchedTimes(DepreciationPosted::class, 2);
        Event::assertDispatched(DepreciationPosted::class, fn (DepreciationPosted $e) => $e->dimensions['cost_centre_id'] === $this->operations->id
            && $e->amounts === ["depreciation.{$this->vehicles->id}" => (string) $january, "accumulated.{$this->vehicles->id}" => (string) $january]);
        Event::assertDispatched(DepreciationPosted::class, fn (DepreciationPosted $e) => $e->dimensions['branch_id'] === $this->branchB->id && $e->date === '2026-01-31');
        $this->assertNotNull($truck);
    }

    public function test_a_month_posts_once_and_runs_post_in_order(): void
    {
        $this->active();
        $this->postRun('2026-01')->assertCreated();

        // Retried: refused, nothing charged twice.
        $this->postRun('2026-01')->assertStatus(409)->assertJsonPath('code', 'run_exists');
        $this->postRun('2026-03')->assertStatus(422)->assertJsonPath('code', 'run_out_of_order');
        $this->assertSame(1, $this->inTenant(fn () => DepreciationLine::query()->count()));
        $this->postRun('2026-02')->assertCreated();
        $this->assertSame(2, $this->inTenant(fn () => DepreciationLine::query()->count()));
    }

    public function test_a_closed_period_refuses_the_run(): void
    {
        $this->active(['acquired_on' => '2026-01-01']);
        $this->closeBooksThrough('2026-01');

        $this->postRun('2026-01')->assertStatus(422)->assertJsonPath('code', 'period_closed');
        $this->api('GET', "runs/preview?company={$this->acme->id}&period=2026-01", [], $this->accountant)->assertOk()->assertJsonPath('data.closed_through', '2026-01-31');
        $this->postRun('2026-02')->assertCreated();
    }

    public function test_an_asset_activated_late_catches_up_in_the_next_run(): void
    {
        $this->active(['name' => 'First']);
        $this->postRun('2026-01')->assertCreated();
        $this->postRun('2026-02')->assertCreated();
        // In service since January, activated after the February run: March charges January to March.
        $late = $this->active(['name' => 'Late']);
        $run = $this->postRun('2026-03')->assertCreated()->json('data');

        $line = collect($run['lines'])->firstWhere('asset.id', $late['id']);
        $this->assertSame(['2026-01-01', '2026-03-31'], [$line['from'], $line['to']]);
        $this->assertSame((string) intdiv(108000000 * 90 * 2 + 1826, 1826 * 2), $line['amount']['amount_minor']);
    }

    public function test_the_mid_month_convention_charges_half_the_first_month_and_locks_once_used(): void
    {
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['convention' => 'mid_month'])->assertOk()->assertJsonPath('data.convention', 'mid_month');
        // 60 months: 120 halves of 900 000.00 each; the first month takes one.
        $this->active(['acquired_on' => '2026-01-20']);
        $run = $this->postRun('2026-01')->assertCreated()->json('data');

        $this->assertSame('900000', $run['lines'][0]['amount']['amount_minor']);
        $this->assertSame('1', $run['lines'][0]['units']);
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['convention' => 'days'])->assertStatus(422)->assertJsonPath('code', 'convention_locked');
    }

    public function test_units_of_production_charge_what_the_meter_used(): void
    {
        // KES 1 000 000.00, no residual (plant has none), 10 000 units.
        $press = $this->active(['category_id' => $this->plant->id, 'cost_minor' => 100000000, 'total_units' => '10000']);
        $this->api('POST', "assets/{$press['id']}/readings", ['read_on' => '2026-01-31', 'reading' => '1500'])->assertCreated();

        $run = $this->postRun('2026-01')->assertCreated()->json('data');
        $this->assertSame(['15000000', '1500'], [$run['lines'][0]['amount']['amount_minor'], $run['lines'][0]['units']]);
        // No reading in February: nothing charged, the span waits for the next reading.
        $this->assertSame([], $this->postRun('2026-02')->assertCreated()->json('data.lines'));
        $this->api('POST', "assets/{$press['id']}/readings", ['read_on' => '2026-03-31', 'reading' => '4000'])->assertCreated();
        $run = $this->postRun('2026-03')->assertCreated()->json('data');
        $this->assertSame(['2026-02-01', '25000000', '2500'], [$run['lines'][0]['from'], $run['lines'][0]['amount']['amount_minor'], $run['lines'][0]['units']]);
    }

    public function test_the_latest_run_reverses_while_nothing_depends_on_it(): void
    {
        Event::fake([DepreciationPosted::class, DepreciationReversed::class]);
        $van = $this->active();
        $first = $this->postRun('2026-01')->assertCreated()->json('data');
        $second = $this->postRun('2026-02')->assertCreated()->json('data');

        $this->api('POST', "runs/{$first['id']}/reverse", ['reason' => 'Wrong'], $this->accountant)->assertStatus(409)->assertJsonPath('code', 'run_not_latest');
        $this->api('POST', "runs/{$second['id']}/reverse", ['reason' => 'Wrong month'], $this->accountant)->assertOk()->assertJsonPath('data.status', 'reversed');

        $this->api('GET', "assets/{$van['id']}")->assertJsonPath('data.depreciated_to', '2026-01-31')->assertJsonPath('data.accumulated.amount_minor', $first['lines'][0]['amount']['amount_minor']);
        Event::assertDispatched(DepreciationReversed::class, fn (DepreciationReversed $e) => $e->reverse && $e->amounts === $second['lines'] ? true : $e->reverse);
        // The month can post again.
        $this->postRun('2026-02')->assertCreated();
    }

    public function test_the_schedule_shows_posted_charges_and_the_projection_to_the_residual_value(): void
    {
        $van = $this->active();
        $this->postRun('2026-01')->assertCreated();

        $schedule = $this->api('GET', "assets/{$van['id']}/schedule")->assertOk()->json('data');
        $this->assertCount(1, $schedule['posted']);
        $this->assertTrue($schedule['projectable']);
        $total = array_sum(array_map(fn (array $row) => (int) $row['amount'], [...$schedule['posted'], ...$schedule['projected']]));
        $this->assertSame(108000000, $total);
        $this->assertSame('12000000', end($schedule['projected'])['book_value']);
    }

    public function test_run_permissions_and_scope(): void
    {
        $this->active();
        $manager = $this->manager;
        // A branch manager neither posts nor reads runs; an accountant of another company's scope cannot either.
        $this->postRun('2026-01', $manager)->assertForbidden();
        $this->api('GET', 'runs', [], $manager)->assertForbidden();
        $atBranch = $this->userWith('accountant', Scope::branch($this->branchA->id));
        $this->postRun('2026-01', $atBranch)->assertForbidden();
        $run = $this->postRun('2026-01')->assertCreated()->json('data');
        $this->api('GET', "runs/{$run['id']}", [], $manager)->assertForbidden();
        $this->api('GET', 'runs', [], $this->accountant)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $this->inTenant(fn () => Asset::query()->whereNotNull('depreciated_to')->count()));
    }
}
