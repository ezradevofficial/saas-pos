<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\ApiRateLimits;
use App\Core\Api\Idempotency;
use App\Core\Api\Models\ApiKey;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

// API-01 (ADR 020): rate limits per key and per tenant with RateLimit-*
// headers and the 429 (API-03), idempotent POST replay, and deprecation
// and sunset headers.
class ApiConventionsTest extends TestCase
{
    use BuildsApiKeys, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_each_key_has_its_own_limit_with_headers_and_a_translated_429(): void
    {
        config(['api.rate_limits.key_per_minute' => 2]);
        $key = $this->apiKey();
        $other = $this->apiKey(['name' => 'Other']);

        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk()
            ->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1');
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk()->assertHeader('RateLimit-Remaining', '0');

        $refused = $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret'], ['Accept-Language' => 'fr']))
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After')
            ->assertHeader('RateLimit-Remaining', '0')
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertStringStartsWith('Trop de requêtes', $refused->json('message'));
        $this->assertSame(429, $refused->json('status'));

        // Another key of the tenant has its own window.
        $this->getJson('/api/v1/companies', $this->keyHeaders($other['secret']))->assertOk();

        // The window resets after a minute.
        $this->travel(61)->seconds();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
    }

    public function test_the_tenant_limit_spans_its_keys(): void
    {
        config(['api.rate_limits.key_per_minute' => 100, 'api.rate_limits.tenant_per_minute' => 3]);
        $one = $this->apiKey();
        $two = $this->apiKey(['name' => 'Two']);

        $this->getJson('/api/v1/companies', $this->keyHeaders($one['secret']))->assertOk()->assertHeader('RateLimit-Limit', '3');
        $this->getJson('/api/v1/companies', $this->keyHeaders($two['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($one['secret']))->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $this->getJson('/api/v1/companies', $this->keyHeaders($two['secret']))->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        // People's requests are not counted against the API.
        $this->getJson('/api/v1/companies', $this->headersFor())->assertOk()->assertHeaderMissing('RateLimit-Limit');
    }

    public function test_a_plan_sets_the_tenant_limit_from_the_catalogue(): void
    {
        config(['api.rate_limits.tenant_per_minute' => 600]);
        DB::connection('pgsql_platform')->table('catalogue_bundles')->where('key', 'starter')->update(['api_requests_per_minute' => 42]);
        $this->inTenant(fn () => DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'country' => 'KE', 'currency' => 'KES', 'cycle' => 'monthly',
            'bundle' => 'starter', 'status' => 'trial', 'trial_ends_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $tenant = $this->owner->tenant_id;
        $this->assertSame(42, $this->inTenant(fn () => app(ApiRateLimits::class)->tenantLimit($tenant)));
        config(['api.rate_limits.key_per_minute' => 1000]);
        $key = $this->apiKey();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk()->assertHeader('RateLimit-Limit', '42');

        // Without a plan limit, the configured default.
        $other = $this->otherTenant()['user']->tenant_id;
        $this->assertSame(600, $this->asTenant($other, fn () => app(ApiRateLimits::class)->tenantLimit($other)));
    }

    public function test_an_idempotent_post_runs_once_and_replays_its_response(): void
    {
        $key = $this->apiKey();
        $body = ['kind' => 'person', 'name' => 'Achieng', 'roles' => ['customer']];
        $headers = $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'order-2026-0001']);

        $first = $this->postJson('/api/v1/parties', $body, $headers)->assertCreated()->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
        $again = $this->postJson('/api/v1/parties', $body, $headers)->assertCreated()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, $this->inTenant(fn () => Party::where('name', 'Achieng')->count()));

        // The same key with another body is refused; another key is another request.
        $this->postJson('/api/v1/parties', [...$body, 'name' => 'Otieno'], $headers)->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
        $this->postJson('/api/v1/parties', $body, $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'order-2026-0002']))->assertCreated()->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
        $this->assertSame(2, $this->inTenant(fn () => Party::where('name', 'Achieng')->count()));

        // Without the header, every POST runs.
        $this->postJson('/api/v1/parties', $body, $this->keyHeaders($key['secret']))->assertCreated();
        $this->assertSame(3, $this->inTenant(fn () => Party::where('name', 'Achieng')->count()));

        $this->postJson('/api/v1/parties', $body, $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'not valid!']))
            ->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_invalid');
    }

    public function test_an_idempotency_key_in_flight_is_refused_and_an_expired_one_runs_again(): void
    {
        $key = $this->apiKey();
        $body = ['kind' => 'person', 'name' => 'Kamau', 'roles' => ['customer']];
        $headers = $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'in-flight']);
        $hash = hash('sha256', "POST\napi/v1/parties\n".json_encode($body));

        $this->inTenant(fn () => DB::table('api_idempotency_keys')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'api_key_id' => $key['id'], 'key' => 'in-flight',
            'request_hash' => $hash, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->postJson('/api/v1/parties', $body, $headers)->assertConflict()->assertJsonPath('code', 'idempotency_in_progress');
        $this->assertSame(0, $this->inTenant(fn () => Party::where('name', 'Kamau')->count()));

        // Stored responses last a day.
        $this->inTenant(fn () => DB::table('api_idempotency_keys')->where('key', 'in-flight')->update(['expires_at' => CarbonImmutable::now()->subMinute()]));
        $this->postJson('/api/v1/parties', $body, $headers)->assertCreated()->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
        $this->assertSame(1, $this->inTenant(fn () => Party::where('name', 'Kamau')->count()));
    }

    public function test_a_deprecated_operation_announces_its_sunset(): void
    {
        config(['api.deprecations' => ['GET companies' => ['deprecated_at' => '2026-10-01', 'sunset' => '2027-04-01', 'anchor' => 'companies-v1']]]);
        $key = $this->apiKey();

        $response = $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk()
            ->assertHeader('Deprecation', '@'.CarbonImmutable::parse('2026-10-01')->getTimestamp())
            ->assertHeader('Sunset', 'Thu, 01 Apr 2027 00:00:00 GMT');
        $this->assertStringContainsString('/developers/changelog#companies-v1>; rel="deprecation"', $response->headers->get('Link'));

        // People calling the same route see the headers too; other routes do not.
        $this->getJson('/api/v1/companies', $this->headersFor())->assertHeader('Sunset');
        $this->getJson('/api/v1/parties', $this->keyHeaders($key['secret']))->assertOk()->assertHeaderMissing('Deprecation');
    }

    public function test_usage_is_counted_and_last_use_recorded(): void
    {
        $key = $this->apiKey();

        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret'], ['REMOTE_ADDR' => '10.1.2.3']))->assertOk();

        $this->inTenant(function () use ($key) {
            $stored = ApiKey::findOrFail($key['id']);
            $this->assertNotNull($stored->last_used_at);
            $this->assertSame(1, (int) DB::table('api_key_usage_days')->where('api_key_id', $key['id'])->value('requests'));
        });
    }
}
