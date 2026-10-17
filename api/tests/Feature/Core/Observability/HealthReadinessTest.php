<?php

namespace Tests\Feature\Core\Observability;

use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-01: liveness never depends on anything; readiness is 503 when the
 * database, Redis, the queue or storage fails, names only which, and
 * leaves Horizon out unless required. The status snapshot follows it.
 */
class HealthReadinessTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::fake('local');
    }

    public function test_liveness_answers_without_dependencies(): void
    {
        config(['database.redis.default.port' => 1]);

        $this->get('/health/live')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_readiness_is_ok_when_every_dependency_answers(): void
    {
        $response = $this->getJson('/health/ready')->assertOk()->assertJsonPath('status', 'ok');

        foreach (['database', 'redis', 'queue', 'storage'] as $check) {
            $response->assertJsonPath("checks.{$check}.status", 'ok');
        }
        // No Horizon runs in tests: reported, not counted.
        $this->assertContains($response->json('checks.horizon.status'), ['ok', 'warn']);
        Storage::disk('media')->assertDirectoryEmpty('health');
    }

    public function test_a_database_failure_makes_the_host_not_ready(): void
    {
        config([
            'database.connections.health_broken' => array_merge(config('database.connections.pgsql'), ['port' => 1]),
            'observability.health.database_connection' => 'health_broken',
        ]);

        $this->getJson('/health/ready')->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.database.status', 'fail')
            ->assertJsonPath('checks.storage.status', 'ok');
    }

    public function test_a_redis_failure_makes_the_host_not_ready(): void
    {
        config(['database.redis.default.port' => 1]);
        app('redis')->purge('default');

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.redis.status', 'fail');
    }

    public function test_a_queue_failure_makes_the_host_not_ready(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.connection' => 'no-such-connection']);

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.queue.status', 'fail');
    }

    public function test_a_storage_failure_makes_the_host_not_ready(): void
    {
        config(['observability.health.storage_disk' => 'no-such-disk']);

        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.storage.status', 'fail');
    }

    public function test_horizon_counts_only_when_required(): void
    {
        config(['database.redis.horizon' => array_merge(config('database.redis.default'), ['port' => 1])]);
        config(['observability.health.require_horizon' => true]);

        $response = $this->getJson('/health/ready');

        if ($response->json('checks.horizon.status') === 'ok') {
            $this->markTestSkipped('A Horizon master is running against this Redis.');
        }
        $response->assertStatus(503)->assertJsonPath('checks.horizon.status', 'fail');
    }

    public function test_failure_details_never_reach_the_response(): void
    {
        config(['observability.health.storage_disk' => 'no-such-disk']);

        $body = $this->getJson('/health/ready')->assertStatus(503)->getContent();

        $this->assertStringNotContainsString('no-such-disk', $body);
        $this->assertStringNotContainsString('Exception', $body);
    }

    public function test_the_status_snapshot_names_components_only(): void
    {
        $this->artisan('health:status')->assertSuccessful();

        $snapshot = json_decode(Storage::disk('local')->get('status/status.json'), true);
        $this->assertSame(['status', 'components', 'generated_at'], array_keys($snapshot));
        $this->assertSame('operational', $snapshot['components']['api']);
        $this->assertContains($snapshot['components']['background_jobs'], ['operational', 'degraded']);
    }

    public function test_the_status_snapshot_reports_an_outage(): void
    {
        config(['observability.health.storage_disk' => 'no-such-disk']);

        $this->artisan('health:status')->assertFailed();

        $snapshot = json_decode(Storage::disk('local')->get('status/status.json'), true);
        $this->assertSame('down', $snapshot['status']);
        $this->assertSame('down', $snapshot['components']['api']);
    }
}
