<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-09: users see and end their sessions; idle sessions time out.
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

    public function test_session_timeout_setting_reads_are_clamped(): void
    {
        $this->assertSame(15, SessionTimeout::minutes(new Tenant(['settings' => ['session_timeout_minutes' => 1]])));
        $this->assertSame(480, SessionTimeout::minutes(new Tenant(['settings' => ['session_timeout_minutes' => 9999]])));
        $this->assertSame(60, SessionTimeout::minutes(new Tenant(['settings' => ['session_timeout_minutes' => 'x']])));
        $this->assertSame(60, SessionTimeout::minutes(null));
    }
}
