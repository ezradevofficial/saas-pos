<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\FixedAssets\Events\DepreciationPosted;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\TaxBook\TaxClasses;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Modules\HR\Models\Employee;
use PDO;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The stage 7D review of Fixed assets (items 3 to 13 and 15): runs serialised
// with disposals, revaluations and estimates; catch-up depreciation before
// a revaluation; open disposals and future months refuse a run; custodians
// for company users and active employees only; mid-month imports; meter
// readings after a charge; impairment losses reversed through profit or
// loss; the tax book's acquisition rule, effective dates and disposals;
// labels at each asset's place.
class ReviewFixesTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    /** Another session holds advisory lock $key; the request under test must wait for it (lock_timeout ends the wait). */
    private function whileLocked(string $key, callable $request): mixed
    {
        $config = config('database.connections.pgsql');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->prepare('select pg_advisory_lock(hashtextextended(?, 0))')->execute([$key]);
        DB::statement("set lock_timeout = '300ms'");

        try {
            return $request();
        } finally {
            $other->prepare('select pg_advisory_unlock(hashtextextended(?, 0))')->execute([$key]);
        }
    }

    public function test_disposals_revaluations_and_estimates_wait_for_the_companys_run_lock(): void
    {
        $asset = $this->active();
        $disposal = $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'scrap', 'disposal_date' => '2026-03-31', 'reason' => 'Crash'])->json('data');
        $key = 'fa_runs:'.$this->acme->id;

        $this->inTenant(fn () => Disposal::query()->whereKey($disposal['id'])->update(['status' => 'approved']));
        $this->whileLocked($key, fn () => $this->api('POST', "disposals/{$disposal['id']}/post")->assertStatus(500));
    }

    public function test_a_revaluation_waits_for_the_run_lock(): void
    {
        $asset = $this->active();
        $this->whileLocked('fa_runs:'.$this->acme->id, fn () => $this->api('POST', "assets/{$asset['id']}/revaluations",
            ['kind' => 'impairment', 'revalued_on' => '2026-03-31', 'value_minor' => 1, 'reason' => 'x'])->assertStatus(500));
    }

    public function test_a_change_of_estimate_waits_for_the_run_lock(): void
    {
        $asset = $this->active();
        $this->whileLocked('fa_runs:'.$this->acme->id, fn () => $this->api('POST', "assets/{$asset['id']}/estimates",
            ['useful_life_months' => 48, 'reason' => 'x'])->assertStatus(500));
    }

    public function test_a_revaluation_first_charges_depreciation_up_to_its_date(): void
    {
        Event::fake([DepreciationPosted::class]);
        $asset = $this->active();
        $charge = intdiv(108000000 * 90 * 2 + 1826, 1826 * 2);

        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-03-31', 'value_minor' => 130000000, 'reason' => 'Valuer'])
            ->assertCreated()->assertJsonPath('data.surplus_change.amount_minor', (string) (130000000 - (120000000 - $charge)));
        $this->api('GET', "assets/{$asset['id']}")->assertJsonPath('data.depreciated_to', '2026-03-31');
        Event::assertDispatched(DepreciationPosted::class, fn (DepreciationPosted $e) => $e->amounts["depreciation.{$this->vehicles->id}"] === (string) $charge && $e->date === '2026-03-31');
    }

    public function test_a_run_is_refused_while_disposals_on_or_before_its_end_are_open(): void
    {
        $asset = $this->active();
        $disposal = $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'scrap', 'disposal_date' => '2026-01-20', 'reason' => 'Crash'])->json('data');

        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertStatus(422)
            ->assertJsonPath('code', 'disposals_open')->assertJsonPath('disposals.0', $disposal['number']);
        $this->api('POST', "disposals/{$disposal['id']}/cancel", ['reason' => 'Mistake'])->assertOk();
        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertCreated();
    }

    public function test_a_run_of_a_month_not_started_is_refused(): void
    {
        $this->active();
        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-11'])->assertStatus(422)->assertJsonPath('code', 'period_future');
        $this->api('GET', "runs/preview?company={$this->acme->id}&period=2026-10")->assertOk();
    }

    public function test_custodians_answer_to_who_manages_assets_anywhere_in_the_company(): void
    {
        $this->api('GET', "custodians?company={$this->acme->id}", [], $this->manager)->assertOk();
        $this->api('GET', "custodians?company={$this->acme->id}", [], $this->userWith('cashier', Scope::location($this->locationA->id)))->assertForbidden();
    }

    public function test_custodians_are_active_employees_of_an_active_hr(): void
    {
        [$active, $gone] = $this->inTenant(function () {
            $make = fn (string $number, string $status) => Employee::query()->create(['number' => $number, 'company_id' => $this->acme->id, 'first_name' => 'Amina', 'last_name' => $number,
                'hire_date' => '2026-01-05', 'status' => $status, 'termination_date' => $status === 'active' ? null : '2026-06-30'])->id;

            return [$make('E-1', 'active'), $make('E-2', 'terminated')];
        });
        // Payroll alone is not HR: no employee is known.
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Laptop', 'custodian_employee_id' => $active])->assertStatus(422)->assertJsonValidationErrors('custodian_employee_id');

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('hr'));
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Laptop', 'custodian_employee_id' => $gone])->assertStatus(422)->assertJsonValidationErrors('custodian_employee_id');
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Laptop', 'custodian_employee_id' => $active])->assertCreated();
    }

    public function test_a_mid_month_import_needs_a_month_end_depreciated_to_date(): void
    {
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['convention' => 'mid_month'])->assertOk();
        $csv = tempnam(sys_get_temp_dir(), 'fa-import-');
        file_put_contents($csv, "Location,Tag,Name,Category,Acquisition date,Cost,Accumulated depreciation,Depreciated to\n"
            ."Outlet A,MID-1,Old truck,VEH,2024-01-01,2400000.00,960000.00,2025-12-15\n"
            ."Outlet A,MID-2,Old van,VEH,2024-01-01,2400000.00,960000.00,2025-12-31\n");
        $import = $this->post('/api/v1/imports', ['importer' => 'fixed_assets', 'file' => new UploadedFile($csv, 'register.csv', 'text/csv', null, true)],
            [...$this->headersFor(), 'Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->json('PATCH', "/api/v1/imports/{$import}", ['mode' => 'create'], $this->headersFor())->assertOk();
        $this->json('POST', "/api/v1/imports/{$import}/run", [], $this->headersFor())->assertStatus(202);

        $this->assertSame(['MID-2'], $this->inTenant(fn () => Asset::query()->pluck('number')->all()));
    }

    public function test_readings_of_a_units_of_production_asset_never_fall_in_a_charged_span(): void
    {
        $press = $this->active(['category_id' => $this->plant->id, 'cost_minor' => 100000000, 'total_units' => '10000']);
        $this->api('POST', "assets/{$press['id']}/readings", ['read_on' => '2026-01-31', 'reading' => '1500'])->assertCreated();
        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertCreated();

        $this->api('POST', "assets/{$press['id']}/readings", ['read_on' => '2026-01-20', 'reading' => '1000'])->assertStatus(422)->assertJsonPath('code', 'reading_depreciated');
        $this->api('POST', "assets/{$press['id']}/readings", ['read_on' => '2026-02-10', 'reading' => '1700'])->assertCreated();
    }

    public function test_an_impairment_loss_is_reversed_through_profit_or_loss_first(): void
    {
        $land = $this->active(['category_id' => $this->land->id, 'cost_minor' => 500000000]);
        $this->api('POST', "assets/{$land['id']}/revaluations", ['kind' => 'impairment', 'revalued_on' => '2026-06-30', 'value_minor' => 400000000, 'reason' => 'Flood'])->assertCreated();

        // Up 60 000.00: it reverses the earlier 100 000.00 loss as far as it goes, nothing to the surplus.
        $this->api('POST', "assets/{$land['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-09-30', 'value_minor' => 460000000, 'reason' => 'Drained'])
            ->assertCreated()->assertJsonPath('data.pnl_change.amount_minor', '60000000')->assertJsonPath('data.surplus_change.amount_minor', '0');
    }

    public function test_the_tax_book_needs_a_confirmed_acquisition_rule_and_follows_effective_dates_and_disposals(): void
    {
        $classes = [
            ['key' => 'machinery', 'method' => 'reducing_balance', 'rate' => '25', 'needs_confirmation' => false, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31'],
            ['key' => 'machinery', 'method' => 'reducing_balance', 'rate' => '20', 'needs_confirmation' => false, 'effective_from' => '2027-01-01', 'effective_to' => null],
        ];
        app(TaxClasses::class)->override('KE', ['classes' => $classes]);

        foreach ([$this->vehicles, $this->computers, $this->plant, $this->land] as $category) {
            $this->api('PATCH', "categories/{$category->id}", ['tax_class' => 'machinery'])->assertOk();
        }

        // No year-of-acquisition rule confirmed: still refused.
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['tax_book' => true])->assertStatus(422)->assertJsonPath('code', 'tax_rates_unconfirmed');

        app(TaxClasses::class)->override('KE', ['classes' => $classes, 'acquisition_rule' => ['value' => 'pro_rata_months', 'needs_confirmation' => false]]);
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['tax_book' => true])->assertOk();
        $van = $this->active(['acquired_on' => '2026-04-01', 'cost_minor' => 120000000]);

        // 2026: 25 % for 9 months of 12; 2027: 20 % on what is left.
        $first = collect($this->api('GET', "companies/{$this->acme->id}/tax-book?year=2026")->json('data'))->firstWhere('asset_id', $van['id']);
        $this->assertSame('22500000', $first['allowance']['amount_minor']);
        $second = collect($this->api('GET', "companies/{$this->acme->id}/tax-book?year=2027")->json('data'))->firstWhere('asset_id', $van['id']);
        $this->assertSame(['97500000', '19500000'], [$second['opening']['amount_minor'], $second['allowance']['amount_minor']]);

        // Disposed in 2027: no allowance that year; the row says so with the base proceeds.
        $this->inTenant(fn () => Asset::query()->whereKey($van['id'])->update(['status' => 'disposed', 'disposed_on' => '2027-06-30']));
        $disposed = collect($this->api('GET', "companies/{$this->acme->id}/tax-book?year=2027")->json('data'))->firstWhere('asset_id', $van['id']);
        $this->assertSame([true, '0'], [$disposed['disposed'], $disposed['allowance']['amount_minor']]);
    }

    public function test_schedules_and_work_orders_filter_by_company_through_their_asset(): void
    {
        // Found by the isolation suite: these tables carry no company_id of their own.
        $asset = $this->active();
        $this->api('POST', "assets/{$asset['id']}/schedules", ['name' => 'Service', 'basis' => 'time', 'interval_days' => 90])->assertCreated();

        $this->assertCount(1, $this->api('GET', "schedules?company={$this->acme->id}")->assertOk()->json('data'));
        $this->api('GET', "work-orders?company={$this->acme->id}")->assertOk();
    }

    public function test_labels_need_the_print_permission_at_each_assets_place(): void
    {
        $asset = $this->active();
        // A reader at the company who prints labels only at branch B.
        $reader = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $this->inTenant(fn () => $this->assign($reader, $this->roles->get('branch_manager'), Scope::branch($this->branchB->id)));

        $this->api('POST', 'labels', ['labels' => [['asset_id' => $asset['id'], 'copies' => 1]]], $reader)->assertStatus(422)->assertJsonPath('code', 'asset_unknown');
    }
}
