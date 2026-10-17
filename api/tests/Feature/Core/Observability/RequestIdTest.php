<?php

namespace Tests\Feature\Core\Observability;

use App\Core\Observability\Http\RequestId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class RequestIdProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = Context::get(RequestId::CONTEXT_KEY);
    }
}

/**
 * NFR-10: every response carries X-Request-Id; a caller's well-formed id
 * is kept, anything else is replaced; the id is on the request's Context
 * (so on every log line) and travels into the jobs the request queues.
 */
class RequestIdTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_a_response_gets_a_new_id(): void
    {
        $id = $this->get('/health/live')->assertOk()->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
    }

    public function test_a_well_formed_id_from_the_caller_is_kept(): void
    {
        $this->get('/health/live', ['X-Request-Id' => 'lb-1234abcd.ef'])
            ->assertHeader('X-Request-Id', 'lb-1234abcd.ef');
    }

    public function test_a_malformed_id_is_replaced(): void
    {
        foreach (["evil\nInjected: yes", 'short', str_repeat('a', 200), 'has space in it'] as $bad) {
            $id = $this->get('/health/live', ['X-Request-Id' => $bad])->headers->get('X-Request-Id');
            $this->assertNotSame($bad, $id);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
        }
    }

    public function test_api_errors_carry_the_id_too(): void
    {
        $this->getJson('/api/v1/me', ['X-Request-Id' => 'req-unauthenticated-1'])
            ->assertUnauthorized()
            ->assertHeader('X-Request-Id', 'req-unauthenticated-1');
    }

    public function test_the_id_travels_into_a_queued_job(): void
    {
        config(['queue.default' => 'database']);
        RequestIdProbeJob::$seen = null;
        Route::get('/_test/request-id-job', function () {
            RequestIdProbeJob::dispatch()->onConnection('database')->onQueue('request-id-test');

            return response()->noContent();
        });

        $this->get('/_test/request-id-job', ['X-Request-Id' => 'req-propagate-0001'])->assertNoContent();

        $payload = json_decode((string) DB::table('jobs')->where('queue', 'request-id-test')->value('payload'), true);
        $this->assertSame('req-propagate-0001', $payload['illuminate:log:context']['data'][RequestId::CONTEXT_KEY] ?? null, json_encode($payload));

        // A worker (another process in production) starts without the request's Context.
        Context::flush();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'request-id-test', '--once' => true]);

        $this->assertSame('req-propagate-0001', RequestIdProbeJob::$seen);
    }
}
