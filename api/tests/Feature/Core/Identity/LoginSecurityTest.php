<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\IdentityNotices;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Sms\SmsSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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
        Mail::fake();
    }

    /** @return Collection<int, NotificationDelivery> $user's new-device alert deliveries (NOT-06) */
    private function alerts(User $user): Collection
    {
        return $this->asTenant($user->tenant_id, fn () => NotificationDelivery::query()
            ->where('event_type', IdentityNotices::NEW_DEVICE)->where('user_id', $user->id)->orderBy('created_at')->get());
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
        $this->assertCount(0, $this->alerts($user));

        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();
        // NOT-02: through the Notifier, by email, tracked in the delivery log.
        $alert = $this->alerts($user)->sole();
        $this->assertSame(['email', 'sent', $user->email, 'en'], [$alert->channel, $alert->status, $alert->recipient, $alert->locale]);
        $this->assertStringContainsString('Device: Device B.', $alert->body);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($user->email)
            && str_starts_with($mail->mailSubject, 'New sign-in to your'));

        // Known now: no second alert.
        $this->travel(2)->minutes();
        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();
        $this->assertCount(1, $this->alerts($user));
    }

    public function test_the_new_device_alert_is_mandatory_and_never_digested(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user, ['User-Agent' => 'Device A']);

        $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [
            ['event_type' => IdentityNotices::NEW_DEVICE, 'channels' => ['email' => false]],
        ]], $this->bearer($token))->assertUnprocessable();
        $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [
            ['event_type' => IdentityNotices::NEW_DEVICE, 'digest' => 'daily'],
        ]], $this->bearer($token))->assertUnprocessable();

        $entry = collect($this->getJson('/api/v1/me/notification-preferences', $this->bearer($token))->assertOk()->json('data'))
            ->firstWhere('event_type', IdentityNotices::NEW_DEVICE);
        $email = collect($entry['channels'])->firstWhere('channel', 'email');
        $this->assertTrue($email['enabled'] && $email['mandatory']);
        $this->assertFalse($entry['digest_allowed']);
        // Invitations go to contacts only: not in users' preferences.
        $this->assertNull(collect($this->getJson('/api/v1/me/notification-preferences', $this->bearer($token))->json('data'))
            ->firstWhere('event_type', IdentityNotices::INVITED));
    }

    public function test_a_user_without_an_email_gets_the_alert_by_sms_through_the_platform_sender(): void
    {
        // No notification SMS provider (production today): the platform
        // SMS sender, the one codes use, carries it (ADR 009).
        config(['notifications.drivers.sms' => 'none']);
        $texts = [];
        $this->app->instance(SmsSender::class, new class($texts) implements SmsSender
        {
            public function __construct(private array &$texts) {}

            public function send(string $to, string $message): void
            {
                $this->texts[] = [$to, $message];
            }
        });

        $user = $this->createUser(['email' => null, 'phone' => '+254700000123', 'phone_verified_at' => now(), 'email_verified_at' => null]);
        $this->signIn('+254700000123', null, ['User-Agent' => 'Device A'])->assertOk();
        $this->signIn('+254700000123', null, ['User-Agent' => 'Device B'])->assertOk();

        $alerts = $this->alerts($user)->keyBy('channel');
        $this->assertSame(['skipped', 'no_email'], [$alerts['email']->status, $alerts['email']->reason]);
        $this->assertSame(['sent', '+254700000123'], [$alerts['sms']->status, $alerts['sms']->recipient]);
        $this->assertCount(1, $texts);
        $this->assertSame('+254700000123', $texts[0][0]);
        $this->assertStringContainsString('new sign-in to your account', $texts[0][1]);
    }

    public function test_a_failed_alert_push_does_not_fail_the_sign_in(): void
    {
        $user = $this->createUser();
        $this->signIn($user->email, null, ['User-Agent' => 'Device A'])->assertOk();

        // The alert's job, onto a queue that cannot be reached.
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue unavailable'));

        $token = $this->signIn($user->email, null, ['User-Agent' => 'Device B'])
            ->assertOk()
            ->json('token');

        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
    }

    public function test_a_failed_attempt_does_not_make_a_device_known(): void
    {
        $user = $this->createUser();

        $this->signIn($user->email, null, ['User-Agent' => 'Device A'])->assertOk();
        $this->signIn($user->email, 'not-the-password', ['User-Agent' => 'Device B'])->assertStatus(422);
        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();

        $this->assertCount(1, $this->alerts($user));
    }

    public function test_local_and_international_spellings_share_the_login_limit(): void
    {
        $user = $this->createUser(['email' => null, 'phone' => '+243812345678', 'phone_verified_at' => now(), 'email_verified_at' => null]);

        for ($i = 0; $i < 3; $i++) {
            $this->signIn('0812345678', 'not-the-password');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->signIn('+243812345678', 'not-the-password');
        }

        $this->signIn('+243812345678')->assertStatus(429);
        $this->signIn('0812345678')->assertStatus(429);
    }
}
