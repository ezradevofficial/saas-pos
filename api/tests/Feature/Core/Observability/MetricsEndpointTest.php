<?php

namespace Tests\Feature\Core\Observability;

use App\Core\Observability\Metrics\MetricsStore;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-10: /metrics is never public. It answers only the configured bearer
 * token or an allow-listed address, and is 404 for anyone else (and for
 * everyone when neither is set). It reports request counts and latency by
 * route pattern, queue sizes, Horizon failures and database health.
 */
class MetricsEndpointTest extends TestCase
{
    use RefreshTenantDatabase;

    private const TOKEN = 'metrics-test-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        app(MetricsStore::class)->flush();
    }

    public function test_it_is_hidden_when_no_token_or_network_is_configured(): void
    {
        config(['observability.metrics.token' => null, 'observability.metrics.allow' => []]);

        $this->get('/metrics')->assertNotFound();
        $this->get('/metrics', ['Authorization' => 'Bearer '])->assertNotFound();
    }

    public function test_a_wrong_or_missing_token_is_refused(): void
    {
        config(['observability.metrics.token' => self::TOKEN]);

        $this->get('/metrics')->assertNotFound();
        $this->get('/metrics', ['Authorization' => 'Bearer wrong-token'])->assertNotFound();
        $this->get('/metrics?token='.self::TOKEN)->assertNotFound();
    }

    public function test_an_address_outside_the_allow_list_is_refused(): void
    {
        config(['observability.metrics.allow' => ['192.168.128.0/17']]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/metrics')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.130.4'])->get('/metrics')->assertOk();
    }

    public function test_it_reports_requests_queues_horizon_and_the_database(): void
    {
        config(['observability.metrics.token' => self::TOKEN]);

        $this->get('/health/live')->assertOk();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/companies/0199f5a2-0000-7000-8000-000000000001')->assertUnauthorized();

        $response = $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $body = $response->getContent();

        $this->assertStringContainsString('http_requests_total{method="GET",route="api/v1/me",status="4xx"} 1', $body);
        // Route patterns, never ids.
        $this->assertStringNotContainsString('0199f5a2-0000-7000-8000-000000000001', $body);
        $this->assertStringContainsString('http_request_duration_seconds_bucket{method="GET",route="api/v1/me",le="+Inf"} 1', $body);
        $this->assertStringContainsString('http_request_duration_seconds_count{method="GET",route="api/v1/me"} 1', $body);
        // Probes are not counted.
        $this->assertStringNotContainsString('route="health/live"', $body);
        $this->assertMatchesRegularExpression('/^app_queue_size\{queue="default"\} -?\d+/m', $body);
        $this->assertMatchesRegularExpression('/^app_queue_size\{queue="exports"\} -?\d+/m', $body);
        $this->assertMatchesRegularExpression('/^app_horizon_failed_jobs\{window="recent"\} -?\d+/m', $body);
        $this->assertMatchesRegularExpression('/^app_horizon_masters -?\d+/m', $body);
        $this->assertStringContainsString('app_database_up 1', $body);
        $this->assertMatchesRegularExpression('/^app_database_ping_seconds [0-9.]+/m', $body);
    }

    public function test_a_database_outage_shows_as_down(): void
    {
        config([
            'observability.metrics.token' => self::TOKEN,
            'database.connections.health_broken' => array_merge(config('database.connections.pgsql'), ['port' => 1]),
            'observability.health.database_connection' => 'health_broken',
        ]);

        $this->get('/metrics', ['Authorization' => 'Bearer '.self::TOKEN])->assertOk()->assertSee('app_database_up 0');
    }
}
