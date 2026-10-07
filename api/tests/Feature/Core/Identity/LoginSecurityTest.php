<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\Notifications\NewDeviceSignIn;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-10: rate limiting, lockout after repeated failures, new-device alerts.
class LoginSecurityTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_five_wrong_passwords_lock_the_account_for_fifteen_minutes(): void
    {
        $user = $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->signIn($user->email, 'not-the-password')->assertStatus(422);
        }

        // Past the per-minute rate limit, still inside the lock.
        $this->travel(2)->minutes();

        $this->signIn($user->email)
            ->assertStatus(423)
            ->assertJsonPath('code', 'locked')
            ->assertHeader('Retry-After')
            ->assertJsonMissingPath('token');

        $this->travel(14)->minutes();

        $this->signIn($user->email)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $user = $this->createUser();

        for ($i = 0; $i < 4; $i++) {
            $this->signIn($user->email, 'not-the-password')->assertStatus(422);
        }
        $this->travel(2)->minutes();
        $this->signIn($user->email)->assertOk();
        $this->signIn($user->email, 'not-the-password')->assertStatus(422);
        $this->travel(2)->minutes();

        $this->signIn($user->email)->assertOk();
    }

    public function test_the_sixth_attempt_in_a_minute_for_one_login_is_throttled(): void
    {
        $user = $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.{$i}.1"])->signIn($user->email, 'not-the-password');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.1'])
            ->signIn($user->email)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_the_login_limit_ignores_case_and_phone_format(): void
    {
        $user = $this->createUser(['email' => null, 'phone' => '+254712345678', 'phone_verified_at' => now(), 'email_verified_at' => null]);

        foreach (['+254712345678', '0712345678', '0712 345 678', '+254 712 345 678', '254712345678'] as $login) {
            $this->signIn($login, 'not-the-password');
        }

        $this->signIn('+254712345678')->assertStatus(429);
    }

    public function test_more_than_ten_attempts_a_minute_from_one_ip_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn("user{$i}@example.com", 'whatever')->assertStatus(422);
        }

        $this->signIn('user10@example.com', 'whatever')->assertStatus(429);
    }

    public function test_sign_up_and_verify_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/verify', ['challenge_id' => 'x', 'code' => '1'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/verify', [])->assertStatus(429);
        $this->postJson('/api/v1/auth/sign-up', [])->assertStatus(429);
        $this->postJson('/api/v1/auth/verify/resend', [])->assertStatus(429);
    }

    public function test_a_sign_in_from_a_new_device_alerts_the_user(): void
    {
        $user = $this->createUser();

        // First ever sign-in: nothing to compare with.
        $this->signIn($user->email, null, ['User-Agent' => 'Device A'])->assertOk();
        // Same device, another address in the same /24.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.9'])
            ->signIn($user->email, null, ['User-Agent' => 'Device A'])->assertOk();
        Notification::assertNotSentTo($user, NewDeviceSignIn::class);

        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();
        Notification::assertSentTo($user, NewDeviceSignIn::class, fn ($n, array $channels) => $channels === ['mail']);
        Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);

        // Known now: no second alert.
        $this->travel(2)->minutes();
        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();
        Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
    }

    public function test_a_failed_attempt_does_not_make_a_device_known(): void
    {
        $user = $this->createUser();

        $this->signIn($user->email, null, ['User-Agent' => 'Device A'])->assertOk();
        $this->signIn($user->email, 'not-the-password', ['User-Agent' => 'Device B'])->assertStatus(422);
        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();

        Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
    }
}
