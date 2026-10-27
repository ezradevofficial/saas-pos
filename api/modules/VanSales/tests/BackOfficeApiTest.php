<?php

namespace Modules\VanSales\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\Facades\Artisan;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-01 to VAN-05, VAN-11, VAN-12, VAN-24, VAN-25, RBAC-01 to RBAC-08: the
// back office: vans, routes, stops, the plan, credit review, runs, positions,
// settings and reports, each refused to those without the permission, at
// another branch or in another tenant.
class BackOfficeApiTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    public function test_the_module_is_closed_to_a_tenant_without_it(): void
    {
        $other = $this->otherTenant();

        foreach (['vans', 'routes', 'runs', 'loads', 'collections', 'accounts', 'credit-reviews', 'reconciliations', 'reports/summary'] as $path) {
            $this->getJson("/api/v1/van-sales/{$path}", $this->headersFor($other['user']))->assertForbidden()->assertJsonPath('code', 'module_inactive');
        }
    }

    public function test_a_supervisor_sets_up_a_van_with_its_own_location(): void
    {
        $this->setUpVanSales();

        $created = $this->api('POST', 'van-sales/vans', ['branch_id' => $this->branchA->id, 'name' => 'Van 2', 'plate' => 'KDA 001A', 'rep_id' => $this->rep->id], $this->supervisor)
            ->assertCreated()->assertJsonPath('data.name', 'Van 2')->assertJsonPath('data.rep_name', $this->rep->name)->assertJsonPath('data.device_id', null);
        $location = $this->inTenant(fn () => Location::findOrFail($created->json('data.location_id')));
        $this->assertSame(['store', $this->branchA->id], [$location->type, $location->branch_id]);

        $this->api('POST', 'van-sales/vans', ['branch_id' => $this->branchA->id, 'name' => 'van 2', 'rep_id' => $this->rep->id], $this->supervisor)->assertUnprocessable()->assertJsonPath('code', 'name_taken');
        $this->api('POST', 'van-sales/vans', ['branch_id' => $this->branchB->id, 'name' => 'Van B', 'rep_id' => $this->rep->id], $this->supervisor)->assertForbidden();
        $this->api('POST', 'van-sales/vans', ['branch_id' => $this->branchA->id, 'name' => 'Van 3', 'rep_id' => $this->rep->id], $this->rep)->assertForbidden();
        $this->api('POST', 'van-sales/vans', ['branch_id' => $this->branchA->id, 'rep_id' => $this->rep->id], $this->supervisor)->assertUnprocessable();
        $this->api('POST', 'van-sales/vans', ['branch_id' => $this->branchA->id, 'name' => 'Van 3', 'rep_id' => $this->id()], $this->supervisor)->assertUnprocessable();

        $id = $created->json('data.id');
        $this->api('PUT', "van-sales/vans/{$id}", ['plate' => 'KDB 002B', 'device_id' => $this->vanDevice->id], $this->supervisor)->assertUnprocessable()->assertJsonPath('code', 'device_unknown');
        $this->api('PUT', "van-sales/vans/{$id}", ['plate' => 'KDB 002B'], $this->supervisor)->assertOk()->assertJsonPath('data.plate', 'KDB 002B');
        $this->api('GET', 'van-sales/vans', [], $this->rep)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', 'van-sales/vans', [], $this->supervisor)->assertOk()->assertJsonCount(2, 'data');
        $this->api('POST', "van-sales/vans/{$id}/archive", [], $this->supervisor)->assertOk();
        $this->api('GET', 'van-sales/vans', [], $this->supervisor)->assertOk()->assertJsonCount(1, 'data');

        $this->startRun();
        $this->api('POST', "van-sales/vans/{$this->van->id}/archive", [], $this->supervisor)->assertConflict()->assertJsonPath('code', 'van_has_open_run');
    }

    public function test_routes_stops_and_the_plan(): void
    {
        $this->setUpVanSales();
        $plain = $this->inTenant(fn () => Party::create(['kind' => 'person', 'name' => 'Supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id]));

        $created = $this->api('POST', 'van-sales/routes', ['branch_id' => $this->branchA->id, 'name' => 'Monday round', 'visit_days' => [1], 'van_id' => $this->van->id], $this->supervisor)
            ->assertCreated()->assertJsonPath('data.visit_days', [1]);
        $id = $created->json('data.id');
        $this->api('POST', 'van-sales/routes', ['branch_id' => $this->branchA->id, 'name' => 'Bad', 'visit_days' => [8]], $this->supervisor)->assertUnprocessable();
        $this->api('POST', 'van-sales/routes', ['branch_id' => $this->branchA->id, 'name' => 'Monday round', 'visit_days' => [1]], $this->supervisor)->assertUnprocessable()->assertJsonPath('code', 'name_taken');
        $this->api('POST', 'van-sales/routes', ['branch_id' => $this->branchA->id, 'name' => 'Nope', 'visit_days' => [1]], $this->rep)->assertForbidden();
        $this->api('POST', 'van-sales/routes', ['branch_id' => $this->branchB->id, 'name' => 'Other branch', 'visit_days' => [1]], $this->supervisor)->assertForbidden();

        $this->api('PUT', "van-sales/routes/{$id}/stops", ['stops' => [['customer_id' => $plain->id]]], $this->supervisor)->assertUnprocessable()->assertJsonPath('code', 'customer_unknown');
        $this->api('PUT', "van-sales/routes/{$id}/stops", ['stops' => [['customer_id' => $this->kiosk->id], ['customer_id' => $this->shop->id, 'visit_days' => [2]]]], $this->supervisor)
            ->assertOk()->assertJsonPath('data.stops.0.customer_name', 'Kiosk')->assertJsonPath('data.stops.1.position', 2)->assertJsonPath('data.stops.1.visit_days', [2]);
        $this->api('PUT', "van-sales/routes/{$id}/stops", ['stops' => [['customer_id' => $this->shop->id]]], $this->supervisor)->assertOk()->assertJsonCount(1, 'data.stops');
        $this->api('GET', "van-sales/routes/{$id}", [], $this->supervisor)->assertOk()->assertJsonPath('data.stop_count', 1);

        // 2026-03-02 is a Monday, 2026-03-03 a Tuesday.
        $this->api('GET', 'van-sales/plan?date=2026-03-02&route_id='.$id, [], $this->supervisor)->assertOk()->assertJsonPath('data.routes.0.stops.0.customer_name', 'Corner shop')->assertJsonPath('data.routes.0.stops.0.status', 'pending');
        $this->api('GET', 'van-sales/plan?date=2026-03-03&route_id='.$id, [], $this->supervisor)->assertOk()->assertJsonPath('data.routes', []);

        $this->api('PUT', "van-sales/routes/{$id}", ['visit_days' => [1, 2]], $this->supervisor)->assertOk()->assertJsonPath('data.visit_days', [1, 2]);
        $this->api('POST', "van-sales/routes/{$id}/archive", [], $this->supervisor)->assertOk();
        $this->api('GET', 'van-sales/routes', [], $this->supervisor)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_plan_shows_the_status_of_the_days_visits(): void
    {
        $this->setUpVanSales();
        $run = $this->startRun();
        $this->vsSync(['visits' => [$this->visitRecord($run, $this->shop, ['status' => 'no_sale', 'no_sale_reason' => 'closed'])]])->assertOk();

        $plan = $this->api('GET', 'van-sales/plan?route_id='.$this->route->id, [], $this->supervisor)->assertOk();
        $this->assertSame(['no_sale', 'pending'], array_column($plan->json('data.routes.0.stops'), 'status'));
    }

    public function test_flagged_credit_is_reviewed_once_by_someone_who_may(): void
    {
        $this->setUpVanSales();
        $run = $this->startRun();
        $this->sellOnVan($this->vanSale($run, 1, $this->kiosk, [$this->pay('credit', '112500')]))->assertOk();
        $id = $this->inTenant(fn () => VanSale::query()->sole()->id);

        $this->api('GET', 'van-sales/credit-reviews', [], $this->rep)->assertForbidden();
        $list = $this->api('GET', 'van-sales/credit-reviews', [], $this->supervisor)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flags', ['no_credit_limit'])->assertJsonPath('data.0.customer_name', 'Kiosk')->assertJsonPath('data.0.rep_name', $this->rep->name);
        $this->assertSame($id, $list->json('data.0.id'));

        $this->api('POST', "van-sales/credit-reviews/{$id}/review", ['note' => 'Known customer'], $this->rep)->assertForbidden();
        $this->api('POST', "van-sales/credit-reviews/{$id}/review", ['note' => 'Known customer'], $this->supervisor)->assertOk()->assertJsonPath('data.review_status', 'reviewed')->assertJsonPath('data.review_note', 'Known customer');
        $this->api('POST', "van-sales/credit-reviews/{$id}/review", [], $this->supervisor)->assertConflict();
        $this->api('GET', 'van-sales/credit-reviews', [], $this->supervisor)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'van-sales/credit-reviews?review_status=reviewed', [], $this->supervisor)->assertOk()->assertJsonCount(1, 'data');

        $this->api('GET', 'van-sales/accounts', [], $this->supervisor)->assertOk()->assertJsonPath('data.0.balances.0.amount_minor', '112500');
        $this->api('GET', "van-sales/accounts/{$this->kiosk->id}", [], $this->supervisor)->assertOk()->assertJsonPath('data.entries.0.kind', 'sale');
        $this->api('GET', "van-sales/accounts/{$this->shop->id}", [], $this->supervisor)->assertNotFound();
        $this->api('GET', "van-sales/accounts/{$this->kiosk->id}", [], $this->rep)->assertForbidden();

        $other = $this->otherVanTenant();
        $this->postJson("/api/v1/van-sales/credit-reviews/{$id}/review", [], $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/van-sales/credit-reviews', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_runs_are_listed_with_their_figures_at_the_vans_place_only(): void
    {
        $this->setUpVanSales();
        $run = $this->startRun();
        $visit = $this->visitRecord($run, $this->shop);
        $this->vsSync(['visits' => [$visit]])->assertOk();
        $this->sellOnVan($this->vanSale($run, 1, $this->shop, null, '2', ['visit_id' => $visit['id']]))->assertOk();

        $this->api('GET', 'van-sales/runs', [], $this->supervisor)->assertOk()->assertJsonPath('data.0.visit_count', 1)->assertJsonPath('data.0.sold_visit_count', 1)
            ->assertJsonPath('data.0.sales_totals.0.total_minor', '112500')->assertJsonPath('data.0.rep_name', $this->rep->name);
        $this->api('GET', "van-sales/runs/{$run}", [], $this->supervisor)->assertOk()->assertJsonPath('data.sales.0.customer_name', 'Corner shop')->assertJsonPath('data.visits.0.status', 'sold');
        $this->api('GET', 'van-sales/runs?status=closed', [], $this->supervisor)->assertOk()->assertJsonCount(0, 'data');

        $elsewhere = $this->userWith('route_supervisor', Scope::branch($this->branchB->id));
        $this->api('GET', 'van-sales/runs', [], $elsewhere)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "van-sales/runs/{$run}", [], $elsewhere)->assertForbidden();

        $other = $this->otherVanTenant();
        $this->getJson("/api/v1/van-sales/runs/{$run}", $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_check_in_positions_are_kept_only_when_asked_for_rounded_and_read_by_the_few(): void
    {
        $this->setUpVanSales();
        $run = $this->startRun();
        $checkIn = ['lat' => -1.286389123, 'lng' => 36.817223987, 'accuracy_m' => 12.4, 'at' => $this->at(110)];

        // Off by default: the point is ignored.
        $off = $this->visitRecord($run, $this->shop, ['check_in' => $checkIn]);
        $this->vsSync(['visits' => [$off]])->assertOk();
        $this->inTenant(fn () => $this->assertNull(Visit::findOrFail($off['id'])->checkin_lat));

        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['gps_checkin' => 'optional', 'gps_retention_days' => 7], $this->owner)->assertOk();
        $on = $this->visitRecord($run, $this->kiosk, ['check_in' => $checkIn]);
        $this->vsSync(['visits' => [$on]])->assertOk();

        $this->inTenant(function () use ($on) {
            $visit = Visit::findOrFail($on['id']);
            $this->assertSame(['-1.2864', '36.8172', 12], [(string) $visit->checkin_lat, (string) $visit->checkin_lng, $visit->checkin_accuracy_m]);
        });

        // Never in the run, the audit log or the visit's own JSON.
        $this->assertStringNotContainsString('1.2864', json_encode($this->api('GET', "van-sales/runs/{$run}", [], $this->supervisor)->json()));
        $this->inTenant(function () {
            $this->assertStringNotContainsString('36.8172', json_encode(AuditEntry::query()->get()->toArray()));
            $this->assertStringNotContainsString('36.8172', Visit::query()->first()->toJson());
        });

        $this->api('GET', "van-sales/runs/{$run}/positions", [], $this->rep)->assertForbidden();
        $this->api('GET', "van-sales/runs/{$run}/positions", [], $this->supervisor)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Kiosk')->assertJsonPath('data.0.lat', -1.2864)->assertJsonPath('data.0.lng', 36.8172);

        // Cleared once older than the company's retention (7 days); the visit stays.
        $this->inTenant(fn () => Visit::query()->whereKey($on['id'])->update(['checkin_at' => now()->subDays(8)]));
        Artisan::call('van-sales:purge-positions');
        $this->inTenant(fn () => $this->assertNull(Visit::findOrFail($on['id'])->checkin_lat));
        $this->api('GET', "van-sales/runs/{$run}/positions", [], $this->supervisor)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_settings_are_read_by_the_team_and_changed_by_the_admin(): void
    {
        $this->setUpVanSales();

        $this->api('GET', "van-sales/companies/{$this->acme->id}/settings", [], $this->rep)->assertOk()->assertJsonPath('data.gps_checkin', 'off')
            ->assertJsonPath('data.gps_retention_days', 30)->assertJsonPath('data.flag_offline_credit', true);
        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['gps_checkin' => 'optional'], $this->supervisor)->assertForbidden();
        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['gps_retention_days' => 120], $this->owner)->assertUnprocessable();
        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['gps_checkin' => 'always'], $this->owner)->assertUnprocessable();
        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['gps_checkin' => 'optional', 'gps_retention_days' => 14], $this->owner)->assertOk()->assertJsonPath('data.gps_retention_days', 14);

        $other = $this->otherVanTenant();
        $this->getJson("/api/v1/van-sales/companies/{$this->acme->id}/settings", $this->headersFor($other['user']))->assertNotFound();
        $this->putJson("/api/v1/van-sales/companies/{$this->acme->id}/settings", ['gps_checkin' => 'optional'], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_the_summary_gives_sales_strike_rate_collections_and_credit(): void
    {
        $this->setUpVanSales();
        $run = $this->startRun();
        $sold = $this->visitRecord($run, $this->shop);
        $this->vsSync(['visits' => [$sold, $this->visitRecord($run, $this->kiosk, ['status' => 'no_sale', 'no_sale_reason' => 'closed'])]])->assertOk();
        $this->sellOnVan($this->vanSale($run, 1, $this->shop, [$this->pay('credit', '112500')], '2', ['visit_id' => $sold['id']]))->assertOk();

        $summary = $this->api('GET', 'van-sales/reports/summary', [], $this->supervisor)->assertOk();
        $row = $summary->json('data.rows.0');
        $this->assertEquals([2, 1, 50, 1, '112500'], [$row['visits'], $row['sold_visits'], $row['strike_rate'], $row['sales_count'], $row['sales'][0]['total_minor']]);
        $this->assertSame('112500', $summary->json('data.credit_outstanding.0.amount_minor'));
        $this->assertSame(['Daily route', $this->rep->name], [$row['route_name'], $row['rep_name']]);

        $this->api('GET', 'van-sales/reports/summary', [], $this->rep)->assertForbidden();
        $this->api('GET', 'van-sales/reports/summary?from=2026-02-01&to=2026-01-01', [], $this->supervisor)->assertUnprocessable();
        $stock = $this->api('GET', 'van-sales/reports/van-stock', [], $this->supervisor)->assertOk();
        $this->assertSame(false, $stock->json('inventory'));
    }

    public function test_vans_and_routes_of_another_tenant_are_never_seen_or_changed(): void
    {
        $this->setUpVanSales();
        $other = $this->otherVanTenant();
        $headers = $this->headersFor($other['user']);

        $this->getJson('/api/v1/van-sales/vans', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/van-sales/vans/{$this->van->id}", ['name' => 'Taken'], $headers)->assertNotFound();
        $this->postJson("/api/v1/van-sales/vans/{$this->van->id}/archive", [], $headers)->assertNotFound();
        $this->getJson("/api/v1/van-sales/routes/{$this->route->id}", $headers)->assertNotFound();
        $this->putJson("/api/v1/van-sales/routes/{$this->route->id}/stops", ['stops' => []], $headers)->assertNotFound();
        $this->postJson('/api/v1/van-sales/vans', ['branch_id' => $this->branchA->id, 'name' => 'Hijack', 'rep_id' => $this->rep->id], $headers)->assertStatus(422);
        $this->postJson('/api/v1/van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]], $headers)->assertUnprocessable();
        $this->assertSame(1, $this->inTenant(fn () => Van::query()->count()));
        $this->assertSame(1, $this->inTenant(fn () => Route::query()->count()));
    }
}
