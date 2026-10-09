<?php

namespace Modules\POS\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// LAY-01, TEN-07, RBAC-04, RBAC-08, TEN-01: the POS dashboard sources
// (today's sales, sales by day) answer only holders of pos.sale.view, for
// the locations they reach, only while the module is active, and never
// show another tenant's sales.
class DashboardSourcesTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $shiftA = $this->openShift();
        $this->upload([$this->saleBody($shiftA, 1), $this->saleBody($shiftA, 2)])->assertOk();

        [, $tokenB] = $this->pairedTill($this->locationB, 'Till B');
        $this->ranges(token: $tokenB)->assertOk();
        $shiftB = $this->openShift($tokenB);
        $this->upload([$this->saleBody($shiftB, 501, ['receipt_number' => 'R-L02-000501'])], $tokenB)->assertOk();
    }

    private function source(string $key, array $query = [], $user = null)
    {
        return $this->getJson("/api/v1/dashboard/sources/{$key}?".http_build_query($query), $this->headersFor($user));
    }

    public function test_sales_today_and_by_day_for_the_locations_the_reader_reaches(): void
    {
        $today = $this->source('pos.sales_today')->assertOk()->json('data');
        $this->assertSame(['money', '337500', 'KES', 3, true], [$today['kind'], $today['value'], $today['currency'], $today['count'], $today['complete']]);

        $byDay = $this->source('pos.sales_by_day', ['days' => 7])->assertOk()->json('data');
        $this->assertCount(7, $byDay['points']);
        $this->assertSame(['label' => now($this->acme->timezone)->toDateString(), 'value' => '337500', 'count' => 3], end($byDay['points']));
        $this->assertSame(['value' => '0', 'count' => 0], array_intersect_key($byDay['points'][0], ['value' => 1, 'count' => 1]));
        $this->source('pos.sales_by_day', ['days' => 40])->assertUnprocessable()->assertJsonValidationErrors('days');

        // A manager of branch B sees branch B's sale only (RBAC-04).
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->source('pos.sales_today', [], $managerB)->assertOk()->assertJsonPath('data.count', 1);
        $this->assertSame(1, end($this->source('pos.sales_by_day', [], $managerB)->json('data')['points'])['count']);

        // Without pos.sale.view the source is closed, and dashboards drop its widgets.
        $hr = $this->userWith('hr_officer', Scope::tenant());
        $this->source('pos.sales_today', [], $hr)->assertForbidden();
        $this->assertNotContains('pos.sales_today', array_column($this->getJson('/api/v1/dashboard/sources', $this->headersFor($hr))->json('data'), 'key'));
    }

    public function test_dashboards_skip_pos_widgets_for_readers_without_access_and_once_the_module_is_off(): void
    {
        $widget = fn (string $id, string $type, string $source, int $x) => ['id' => $id, 'type' => $type, 'source' => $source, 'params' => [], 'title' => null, 'x' => $x, 'y' => 0, 'w' => 4, 'h' => 2];
        $saved = $this->postJson('/api/v1/config/dashboard', ['scope_type' => 'tenant', 'payload' => ['widgets' => [
            $widget('sales', 'kpi', 'pos.sales_today', 0),
            ['chart' => 'bar', ...$widget('days', 'chart', 'pos.sales_by_day', 4)],
            $widget('waiting', 'approval_count', 'approvals.waiting', 8),
        ]]], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/config/dashboard/{$saved->json('data.id')}/publish", ['revision' => $saved->json('data.draft.revision')], $this->headersFor())->assertOk();

        $ids = fn ($user = null) => array_column($this->getJson('/api/v1/config/dashboard/resolved', $this->headersFor($user))->assertOk()->json('data.payload.widgets'), 'id');
        $this->assertSame(['sales', 'days', 'waiting'], $ids());
        $this->assertSame(['waiting'], $ids($this->userWith('hr_officer', Scope::tenant())));

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->assertSame(['waiting'], $ids());
        $this->source('pos.sales_today')->assertNotFound();
    }

    public function test_another_tenant_sees_none_of_these_sales(): void
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pos'));

        $this->source('pos.sales_today', [], $other['user'])->assertOk()->assertJsonPath('data.count', 0);
        $points = $this->source('pos.sales_by_day', [], $other['user'])->assertOk()->json('data.points');
        $this->assertSame(0, array_sum(array_column($points, 'count')));
    }
}
