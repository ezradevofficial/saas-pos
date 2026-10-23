<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Api\Auth\ApiKeyAwareGuard;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-09: users see and end their sessions; idle sessions time out, correct to
// within the last-used write interval (NFR-02, see SessionTimeout::isIdle).
class SessionTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_sessions_are_listed_with_the_current_one_flagged(): void
    {
        $user = $this->createUser();
        $first = $this->tokenFor($user, ['User-Agent' => 'Browser A'], ['device_name' => 'Office laptop']);
        $second = $this->tokenFor($user, ['User-Agent' => 'Browser B']);

        $sessions = $this->getJson('/api/v1/auth/sessions', $this->bearer($first))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'ip', 'user_agent', 'last_used_at', 'current']]])
            ->json('data');

        $current = collect($sessions)->where('current', true);
        $this->assertCount(1, $current);
        $this->assertSame('Office laptop', $current->first()['name']);
        $this->assertSame(PersonalAccessToken::findToken($first)->id, $current->first()['id']);
        $this->assertSame('Browser B', collect($sessions)->firstWhere('current', false)['name']);
    }

    public function test_revoking_another_session_signs_it_out(): void
    {
        $user = $this->createUser();
        $first = $this->tokenFor($user);
        $second = $this->tokenFor($user);
        $secondId = PersonalAccessToken::findToken($second)->id;

        $this->deleteJson("/api/v1/auth/sessions/{$secondId}", [], $this->bearer($first))->assertNoContent();

        $this->getJson('/api/v1/me', $this->bearer($second))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($first))->assertOk();
    }

    public function test_another_users_session_is_not_found(): void
    {
        $mine = $this->tokenFor($this->createUser());
        $theirs = $this->tokenFor($this->createUser());
        $theirId = PersonalAccessToken::findToken($theirs)->id;

        $this->deleteJson("/api/v1/auth/sessions/{$theirId}", [], $this->bearer($mine))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->deleteJson('/api/v1/auth/sessions/not-a-uuid', [], $this->bearer($mine))->assertNotFound();

        $this->getJson('/api/v1/me', $this->bearer($theirs))->assertOk();
    }

    public function test_a_token_idle_beyond_the_default_60_minutes_is_refused(): void
    {
        $token = $this->tokenFor($this->createUser());

        $this->travel(61)->minutes();

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_activity_keeps_the_session_alive(): void
    {
        $token = $this->tokenFor($this->createUser());

        $this->travel(50)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->travel(50)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
    }

    public function test_the_tenant_timeout_applies_and_is_clamped(): void
    {
        $cases = [
            [15, 16, false],     // tenant value
            [5, 10, true],       // below 15: treated as 15
            [10000, 479, true],  // above 480: treated as 480
            [10000, 481, false],
        ];

        foreach ($cases as [$setting, $idle, $alive]) {
            $user = $this->createUser([], ['settings' => ['session_timeout_minutes' => $setting]]);
            $token = $this->tokenFor($user);

            $this->travel($idle)->minutes();

            $response = $this->getJson('/api/v1/me', $this->bearer($token));
            $alive ? $response->assertOk() : $response->assertUnauthorized();

            $this->travelBack();
        }
    }

    public function test_a_deactivated_users_tokens_stop_working(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);

        $this->asTenant($user->tenant_id, fn () => $user->forceFill(['status' => 'deactivated'])->save());

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_last_used_at_is_written_at_most_once_per_interval(): void
    {
        // NFR-02: two requests within the 60-second interval make one write; one after it makes another.
        // Frozen, so the request's own run time never moves a step across the 60-second edge.
        $this->freezeTime();
        $token = $this->tokenFor($this->createUser());
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (str_starts_with($query->sql, 'update "personal_access_tokens"')) {
                $writes++;
            }
        });

        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $first = PersonalAccessToken::findToken($token)->last_used_at;
        $this->travel(59)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->assertSame(1, $writes);
        $this->assertEquals($first, PersonalAccessToken::findToken($token)->last_used_at);

        $this->travel(1)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->assertSame(2, $writes);
        $this->assertTrue(PersonalAccessToken::findToken($token)->last_used_at->gt($first));
        DB::getEventDispatcher()->forget(QueryExecuted::class);
    }

    public function test_the_last_used_interval_is_configurable_and_capped(): void
    {
        config(['sanctum.last_used_at_seconds' => 0]);
        $this->assertSame(0, ApiKeyAwareGuard::touchSeconds());
        config(['sanctum.last_used_at_seconds' => 120]);
        $this->assertSame(120, ApiKeyAwareGuard::touchSeconds());
        config(['sanctum.last_used_at_seconds' => 3600]);
        $this->assertSame(ApiKeyAwareGuard::MAX_TOUCH_SECONDS, ApiKeyAwareGuard::touchSeconds());

        // 0 writes on every request.
        config(['sanctum.last_used_at_seconds' => 0]);
        $token = $this->tokenFor($this->createUser());
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (str_starts_with($query->sql, 'update "personal_access_tokens"')) {
                $writes++;
            }
        });
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->assertSame(2, $writes);
        DB::getEventDispatcher()->forget(QueryExecuted::class);
    }

    public function test_the_idle_timeout_is_correct_to_within_the_write_interval(): void
    {
        // AUTH-09 with NFR-02: a request inside the interval is not recorded, so the
        // session ends up to 60 seconds early, counted from the last recorded use, never late.
        $token = $this->tokenFor($this->createUser());

        $this->travel(1)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk(); // recorded
        $this->travel(59)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk(); // within the interval: not recorded

        // 59 minutes 30 seconds after the recorded use: still alive.
        $this->travel(59 * 60 + 30 - 59)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk(); // recorded again
        $this->travel(60)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_an_unrecorded_request_does_not_extend_the_timeout(): void
    {
        // AUTH-09 with NFR-02: the bound in SessionTimeout::isIdle. The real last request
        // was 59 minutes 30 seconds ago, but the recorded one was 60 minutes 29 seconds ago.
        $token = $this->tokenFor($this->createUser());

        $this->travel(1)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk(); // recorded
        $this->travel(59)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk(); // not recorded
        $this->travel(59 * 60 + 30)->seconds();

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    /** @return array<string, array{string}> 21:00 UTC is midnight in Africa/Nairobi; 00:00 UTC is the UTC day change. */
    public static function midnights(): array
    {
        return [
            'Nairobi midnight' => ['2026-10-22T20:59:30Z'],
            'UTC midnight' => ['2026-10-22T23:59:30Z'],
        ];
    }

    #[DataProvider('midnights')]
    public function test_the_idle_timeout_counts_elapsed_time_across_midnight(string $beforeMidnight): void
    {
        // AUTH-09: idleness is the time since the last recorded use, never the calendar date.
        $this->travelTo(CarbonImmutable::parse($beforeMidnight));
        $token = $this->tokenFor($this->createUser());
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();

        // 61 seconds later, on the next day: alive, and the use is recorded (NFR-02 interval passed).
        $this->travel(61)->seconds();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();

        // 59 minutes after that use, well past midnight: still alive.
        $this->travel(59)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();

        // 61 idle minutes: refused, as at any other hour.
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_session_timeout_setting_reads_are_clamped(): void
    {
        $this->assertSame(15, SessionTimeout::minutes(new Tenant(['settings' => ['session_timeout_minutes' => 1]])));
        $this->assertSame(480, SessionTimeout::minutes(new Tenant(['settings' => ['session_timeout_minutes' => 9999]])));
        $this->assertSame(60, SessionTimeout::minutes(new Tenant(['settings' => ['session_timeout_minutes' => 'x']])));
        $this->assertSame(60, SessionTimeout::minutes(null));
    }
}
