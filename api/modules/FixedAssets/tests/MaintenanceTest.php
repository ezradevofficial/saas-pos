<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Artisan;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-19: maintenance schedules by time and by usage, work orders whose
// completion moves the schedule and keeps the cost with its base amount,
// and reminders sent once per due date to who maintains at the place.
class MaintenanceTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    public function test_completing_a_work_order_moves_its_schedule_on(): void
    {
        $asset = $this->active();
        $schedule = $this->api('POST', "assets/{$asset['id']}/schedules", ['name' => 'Service', 'basis' => 'time', 'interval_days' => 90, 'warning_days' => 10], $this->manager)
            ->assertCreated()->json('data');
        $this->assertSame('2026-04-01', $schedule['next_due_on']);
        $usage = $this->api('POST', "assets/{$asset['id']}/schedules", ['name' => 'Tyres', 'basis' => 'usage', 'interval_units' => '20000'], $this->manager)->assertCreated()->json('data');
        $this->assertSame('20000', rtrim(rtrim($usage['next_due_reading'], '0'), '.'));
        $this->api('POST', "assets/{$asset['id']}/schedules", ['name' => 'Bad', 'basis' => 'time'], $this->manager)->assertStatus(422)->assertJsonPath('code', 'schedule_interval');

        $order = $this->api('POST', "assets/{$asset['id']}/work-orders", ['title' => 'Quarterly service', 'schedule_id' => $schedule['id'], 'due_on' => '2026-04-01', 'vendor_id' => $this->buyer->id], $this->manager)
            ->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^WO-/', $order['number']);
        $done = $this->api('PATCH', "work-orders/{$order['id']}", ['status' => 'done', 'completed_on' => '2026-04-03', 'reading' => '15000', 'cost_minor' => 10000, 'currency' => 'USD'], $this->manager)
            ->assertOk()->json('data');

        $this->assertSame(['done', '1300000'], [$done['status'], $done['base_cost']['amount_minor']]);
        $this->api('GET', "schedules?asset={$asset['id']}", [], $this->manager)->assertOk()->assertJsonPath('data.0.next_due_on', '2026-07-02');
        $this->api('GET', "assets/{$asset['id']}/readings")->assertJsonPath('data.0.reading', '15000.000000');
        $this->api('PATCH', "work-orders/{$order['id']}", ['title' => 'Again'], $this->manager)->assertStatus(409)->assertJsonPath('code', 'document_not_open');
    }

    public function test_reminders_go_once_per_due_date_to_who_maintains_there(): void
    {
        $asset = $this->active();
        $this->api('POST', "assets/{$asset['id']}/schedules", ['name' => 'Service', 'basis' => 'time', 'interval_days' => 280, 'warning_days' => 7], $this->manager)->assertCreated();
        $atB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));

        Artisan::call('fixed-assets:maintenance-reminders', ['--at' => '2026-09-30']);
        $this->assertSame(0, $this->inTenant(fn () => InAppNotification::query()->count()));
        Artisan::call('fixed-assets:maintenance-reminders', ['--at' => '2026-10-02']);
        Artisan::call('fixed-assets:maintenance-reminders', ['--at' => '2026-10-03']);

        $recipients = $this->inTenant(fn () => InAppNotification::query()->pluck('user_id')->all());
        $this->assertContains($this->manager->id, $recipients);
        $this->assertNotContains($atB->id, $recipients);
        $this->assertSame(count(array_unique($recipients)), count($recipients), 'one reminder per person per due date');
    }

    public function test_maintenance_needs_its_permission_at_the_place(): void
    {
        $asset = $this->active();
        $atB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->api('POST', "assets/{$asset['id']}/work-orders", ['title' => 'x'], $atB)->assertNotFound();
        $this->api('GET', 'work-orders', [], $this->userWith('cashier', Scope::location($this->locationA->id)))->assertForbidden();
    }
}
