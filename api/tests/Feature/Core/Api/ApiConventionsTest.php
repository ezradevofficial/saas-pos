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
        $hash = hash('sha256', "POST\n/api/v1/parties\n".json_encode($body));

        $this->inTenant(fn () => DB::table('api_idempotency_keys')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'api_key_id' => $key['id'], 'key' => 'in-flight',
            'request_hash' => $hash, 'locked_until' => now()->addSeconds(60), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
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

    public function test_a_stale_claim_is_taken_over_once_its_lock_expires(): void
    {
        // Review item 3: a request that died after claiming its key leaves a null-status row; after 60 s another may run.
        $key = $this->apiKey();
        $body = ['kind' => 'person', 'name' => 'Njeri', 'roles' => ['customer']];
        $headers = $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'stale']);
        $hash = hash('sha256', "POST\n/api/v1/parties\n".json_encode($body));

        $this->inTenant(fn () => DB::table('api_idempotency_keys')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'api_key_id' => $key['id'], 'key' => 'stale',
            'request_hash' => $hash, 'locked_until' => now()->subSecond(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->postJson('/api/v1/parties', $body, $headers)->assertCreated();
        $this->postJson('/api/v1/parties', $body, $headers)->assertCreated()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
        $this->assertSame(1, $this->inTenant(fn () => Party::where('name', 'Njeri')->count()));
    }

    public function test_the_idempotency_hash_covers_the_query_string(): void
    {
        $key = $this->apiKey();
        $body = ['kind' => 'person', 'name' => 'Wairimu', 'roles' => ['customer']];

        $this->postJson('/api/v1/parties?source=crm', $body, $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'q-1']))->assertCreated();
        $this->postJson('/api/v1/parties?source=shop', $body, $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'q-1']))
            ->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
    }

    public function test_expired_idempotency_rows_are_pruned_by_the_scheduled_command(): void
    {
        // Review item 6: a security-definer function deletes expired rows of every tenant.
        $key = $this->apiKey();
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Old', 'roles' => ['customer']], $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'old']))->assertCreated();
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'New', 'roles' => ['customer']], $this->keyHeaders($key['secret'], [Idempotency::HEADER => 'new']))->assertCreated();
        $this->inTenant(fn () => DB::table('api_idempotency_keys')->where('key', 'old')->update(['expires_at' => now()->subMinute()]));

        $this->artisan('api:prune-idempotency')->assertSuccessful();

        $this->assertSame(['new'], $this->inTenant(fn () => DB::table('api_idempotency_keys')->pluck('key')->all()));
    }

    public function test_usage_counts_only_admitted_requests_and_is_flushed_to_the_daily_table(): void
    {
        // Review item 5: counted in the cache (Redis in production) and flushed by a scheduled job.
        config(['api.rate_limits.key_per_minute' => 2]);
        $key = $this->apiKey();

        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertStatus(429);

        $this->assertSame(0, $this->inTenant(fn () => DB::table('api_key_usage_days')->count()), 'nothing written per request');
        $this->getJson("/api/v1/api-keys/{$key['id']}/usage", $this->headersFor())->assertOk()->assertJsonPath('meta.total', 2);

        $this->artisan('api:flush-usage')->assertSuccessful();

        $this->inTenant(function () use ($key) {
            $this->assertNotNull(ApiKey::findOrFail($key['id'])->last_used_at);
            $this->assertSame(2, (int) DB::table('api_key_usage_days')->where('api_key_id', $key['id'])->value('requests'));
        });
        // Flushing again sets the same total (idempotent).
        $this->artisan('api:flush-usage')->assertSuccessful();
        $this->assertSame(2, $this->inTenant(fn () => (int) DB::table('api_key_usage_days')->where('api_key_id', $key['id'])->value('requests')));
    }

    /** Review item 5, Phase 8 review M5: per key id and address, counted once the key fails to resolve. */
    public function test_failed_key_authentications_are_limited_per_key_and_address(): void
    {
        config(['api.rate_limits.failed_auth_per_token' => 3]);
        $wrong = 'sk_live_'.str_repeat('a', 16).'_'.str_repeat('B', 40);

        foreach (range(1, 3) as $i) {
            $this->getJson('/api/v1/companies', $this->keyHeaders($wrong))->assertUnauthorized();
        }

        $this->getJson('/api/v1/companies', $this->keyHeaders($wrong))->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        // Guessing another secret for the same key id is limited too.
        $this->getJson('/api/v1/companies', $this->keyHeaders('sk_live_'.str_repeat('a', 16).'_'.str_repeat('C', 40)))->assertStatus(429);
        // A valid key, and another wrong key, from the same address are not held back by it.
        $key = $this->apiKey();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders('sk_live_'.str_repeat('b', 16).'_'.str_repeat('B', 40)))->assertUnauthorized();
        // Nor is the same key id from another address.
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->getJson('/api/v1/companies', $this->keyHeaders($wrong))->assertUnauthorized();
    }
}
