<?php

namespace Tests\Feature\Core\Limits;

use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Workflow\Models\DocumentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\BuildsUsageLimits;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LIM-04: limits never block operations. The only code that consults them
 * is UsageLimits::ensureCanAdd(), and it is called by exactly the actions
 * that add one more outlet, device or user (employees when HR exists):
 * this test finds every call in the code and every route reaching one.
 * Approvals and workflows still go through while every unit is over the
 * plan past its window (sales and sync: Modules\POS\Tests\UsageLimitsNeverBlockTest).
 */
class UsageLimitGuardsTest extends TestCase
{
    use BuildsApprovals, BuildsUsageLimits, RefreshTenantDatabase;

    /** Every guarded action: file => [function => unit]. */
    private const CALL_SITES = [
        'app/Core/Tenancy/Http/Controllers/LocationController.php' => ['store' => 'OUTLET', 'update' => 'OUTLET', 'restore' => 'OUTLET'],
        'app/Core/Tenancy/Http/Controllers/DeviceController.php' => ['store' => 'DEVICE', 'pairingCode' => 'DEVICE'],
        'app/Core/Tenancy/DevicePairing.php' => ['pair' => 'DEVICE', 'resume' => 'DEVICE'],
        'app/Core/Identity/Http/Controllers/InvitationController.php' => ['store' => 'USER'],
        'app/Core/Identity/Http/Controllers/UserController.php' => ['reactivate' => 'USER'],
    ];

    /** The routes that reach a guarded action: all of them add one more of a unit. */
    private const GUARDED_ROUTES = [
        'POST api/v1/branches/{branch}/locations',
        'PUT|PATCH api/v1/locations/{location}',
        'POST api/v1/locations/{location}/restore',
        'POST api/v1/locations/{location}/devices',
        'POST api/v1/devices/{device}/pairing-code',
        'POST api/v1/devices/pair',
        'POST api/v1/devices/{device}/resume',
        'POST api/v1/invitations',
        'POST api/v1/users/{user}/reactivate',
    ];

    public function test_only_the_add_more_actions_consult_the_limits(): void
    {
        $found = [];

        foreach (['app', 'modules'] as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = $file->getContents();
                $relative = $root.'/'.str_replace('\\', '/', $file->getRelativePathname());

                if ($relative === 'app/Core/Limits/UsageLimits.php') {
                    // The guard itself, and its list of guarded actions.
                    continue;
                }

                preg_match_all('/ensureCanAdd\(Units::([A-Z]+)\)/', $source, $calls, PREG_OFFSET_CAPTURE);

                foreach ($calls[1] as [$unit, $offset]) {
                    preg_match_all('/function\s+(\w+)\s*\(/', substr($source, 0, $offset), $functions);
                    $found[$relative][end($functions[1])] = $unit;
                }

                $this->assertSame(count($calls[1]), substr_count($source, 'ensureCanAdd('), "{$relative}: every call names its unit");
            }
        }

        ksort($found);
        $expected = self::CALL_SITES;
        ksort($expected);
        $this->assertSame($expected, $found);

        // The guarded actions listed for people are the same ones, by unit, and only the four units.
        $this->assertSame(Units::ALL, array_keys(UsageLimits::GUARDED));
        $byUnit = array_count_values(array_merge(...array_map('array_values', array_values(self::CALL_SITES))));
        $this->assertSame([3, 4, 2, 0], array_map(fn (string $unit) => $byUnit[strtoupper($unit)] ?? 0, Units::ALL));
        $this->assertSame([3, 4, 2, 0], array_map('count', array_values(UsageLimits::GUARDED)));
    }

    public function test_the_routes_reaching_a_guarded_action_all_add_one_more(): void
    {
        $guarded = [
            'App\Core\Tenancy\Http\Controllers\LocationController@store',
            'App\Core\Tenancy\Http\Controllers\LocationController@update',
            'App\Core\Tenancy\Http\Controllers\LocationController@restore',
            'App\Core\Tenancy\Http\Controllers\DeviceController@store',
            'App\Core\Tenancy\Http\Controllers\DeviceController@pairingCode',
            // DevicePairing::pair and ::resume.
            'App\Core\Tenancy\Http\Controllers\DevicePairingController@pair',
            'App\Core\Tenancy\Http\Controllers\DeviceController@resume',
            'App\Core\Identity\Http\Controllers\InvitationController@store',
            'App\Core\Identity\Http\Controllers\UserController@reactivate',
        ];
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array($route->getActionName(), $guarded, true)) {
                $routes[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            }
        }

        sort($routes);
        $expected = self::GUARDED_ROUTES;
        sort($expected);
        $this->assertSame($expected, $routes);

        // DevicePairing's pair and resume are reached only from those two routes.
        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() === 'php' && ! str_ends_with($file->getRelativePathname(), 'DevicePairing.php')) {
                preg_match_all('/pairing->(pair|resume)\(/', $file->getContents(), $uses);
                foreach ($uses[1] as $method) {
                    $this->assertContains($file->getFilename().':'.$method, ['DevicePairingController.php:pair', 'DeviceController.php:resume']);
                }
            }
        }
    }

    public function test_approvals_and_workflows_go_through_while_every_unit_is_blocked(): void
    {
        Mail::fake();
        $this->setUpApprovals();
        $this->setUpLimits();
        $this->devices(1);
        $this->overLimitEverywhere();
        $this->assertSame(['blocked', 'blocked', 'blocked'], $this->inTenant(fn () => array_column(array_slice(app(UsageLimits::class)->status(CarbonImmutable::now())['units'], 0, 3), 'state')));

        // A workflow starts and moves to its approval; the manager approves.
        $approval = $this->submit($this->approvalGraph());
        $this->postJson($this->approvalUrl($approval, '/approve'), ['comment' => 'Fine'], $this->headersFor($this->managerA))
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('completed', $this->inTenant(fn () => DocumentWorkflow::query()->find($approval->workflow_id))->status);

        // The guard still refuses adding: the block was real.
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till'], $this->headersFor())->assertStatus(402);
    }
}
