<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Services\Challenges;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-04: password reset by a one-time code sent to the login's email or phone.
class PasswordResetTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    private string $newPassword = 'copper-lantern-77';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // The forgot answer is timeboxed; tests need not wait for it.
        Sleep::fake();
    }

    private function forgot(string $login): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['login' => $login]);
    }

    private function reset(string $login, string $code, ?string $password = null): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'login' => $login,
            'code' => $code,
            'password' => $password ?? $this->newPassword,
        ]);
    }

    private function fresh(User $user): User
    {
        return $this->asTenant($user->tenant_id, fn () => User::findOrFail($user->id));
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_forgot_sends_a_code_by_email_for_an_email_login(): void
    {
        $user = $this->createUser(['phone' => '+254712345678', 'phone_verified_at' => now()]);

        $this->forgot($user->email)
            ->assertStatus(202)
            ->assertExactJson(['message' => __('auth.password_reset.sent')]);

        Notification::assertSentTo($user, VerificationCode::class, fn (VerificationCode $n) => $n->channel === 'email'
            && $n->purpose === 'password_reset'
            && $n->minutes === 30);
    }

    public function test_forgot_sends_a_code_by_sms_for_a_phone_login(): void
    {
        $user = $this->createUser(['phone' => '+254712345678', 'phone_verified_at' => now()]);

        $this->forgot('0712 345 678')->assertStatus(202);

        Notification::assertSentTo($user, VerificationCode::class, fn (VerificationCode $n) => $n->channel === 'sms'
            && $n->purpose === 'password_reset');
    }

    public function test_forgot_for_an_unknown_login_answers_the_same_and_sends_nothing(): void
    {
        $user = $this->createUser();
        $known = $this->forgot($user->email)->assertStatus(202)->json();

        $this->forgot('nobody@example.com')
            ->assertStatus(202)
            ->assertExactJson($known);
        $this->forgot('+254799999999')->assertStatus(202)->assertExactJson($known);

        Notification::assertSentTimes(VerificationCode::class, 1);
    }

    public function test_forgot_inside_the_cooldown_answers_the_same_without_sending(): void
    {
        $user = $this->createUser();

        $this->forgot($user->email)->assertStatus(202);
        $this->forgot($user->email)->assertStatus(202)->assertExactJson(['message' => __('auth.password_reset.sent')]);

        Notification::assertSentTimes(VerificationCode::class, 1);
    }

    public function test_forgot_sends_nothing_to_a_deactivated_user(): void
    {
        $user = $this->createUser(['status' => 'deactivated']);

        $this->forgot($user->email)->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_a_valid_code_changes_the_password_and_revokes_every_session(): void
    {
        $user = $this->createUser();
        $tokens = [$this->tokenFor($user), $this->tokenFor($user)];
        // Locked out after failed sign-ins: the reset unlocks.
        $this->asTenant($user->tenant_id, fn () => User::findOrFail($user->id)
            ->forceFill(['failed_sign_ins' => 3, 'locked_until' => now()->addMinutes(10)])->saveQuietly());

        $this->forgot($user->email);

        $this->reset($user->email, $this->lastCode())
            ->assertOk()
            ->assertExactJson(['message' => __('auth.password_reset.done')]);

        $fresh = $this->fresh($user);
        $this->assertTrue(Hash::check($this->newPassword, $fresh->password));
        $this->assertSame(0, $fresh->failed_sign_ins);
        $this->assertNull($fresh->locked_until);
        $this->assertSame(0, $this->asTenant($user->tenant_id, fn () => $fresh->tokens()->count()));

        foreach ($tokens as $token) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
        }

        $entries = $this->asTenant($user->tenant_id, fn () => AuditEntry::where('action', 'auth.password_reset')->get());
        $this->assertCount(1, $entries);
        $this->assertSame($user->id, $entries[0]->auditable_id);
        $this->assertStringNotContainsString($this->newPassword, json_encode($entries[0]->toArray()));

        // Sign-in, forgot and reset share the per-login limit (AUTH-10).
        $this->travel(61)->seconds();
        $this->signIn($user->email)->assertStatus(422);
        $this->signIn($user->email, $this->newPassword)->assertOk();
    }

    public function test_a_code_works_once(): void
    {
        $user = $this->createUser();
        $this->forgot($user->email);
        $code = $this->lastCode();

        $this->reset($user->email, $code)->assertOk();
        $this->reset($user->email, $code, 'another-quiet-river-9')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_code');

        $this->assertTrue(Hash::check($this->newPassword, $this->fresh($user)->password));
    }

    public function test_a_code_expires_after_thirty_minutes(): void
    {
        $user = $this->createUser();
        $this->forgot($user->email);
        $code = $this->lastCode();

        $this->travel(31)->minutes();

        $this->reset($user->email, $code)->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->assertTrue(Hash::check($this->password, $this->fresh($user)->password));
    }

    public function test_a_wrong_code_or_unknown_login_is_refused(): void
    {
        $user = $this->createUser();
        $this->forgot($user->email);
        $code = $this->lastCode();

        $this->reset($user->email, $this->wrong($code))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->reset('nobody@example.com', $code)->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        // Still usable after a wrong guess.
        $this->reset($user->email, $code)->assertOk();
    }

    public function test_five_wrong_codes_spend_the_code(): void
    {
        $user = $this->createUser();
        $this->forgot($user->email);
        $code = $this->lastCode();

        for ($i = 0; $i < Challenges::MAX_ATTEMPTS; $i++) {
            if ($i === 4) {
                $this->travel(61)->seconds(); // past the per-login limit (AUTH-10)
            }

            $this->reset($user->email, $this->wrong($code))->assertStatus(422);
        }

        $this->reset($user->email, $code)
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.code.attempts'));
        $this->assertTrue(Hash::check($this->password, $this->fresh($user)->password));
    }

    public function test_the_new_password_follows_the_tenant_policy_and_keeps_the_code(): void
    {
        $user = $this->createUser([], ['settings' => ['password_min_length' => 14]]);
        $this->forgot($user->email);
        $code = $this->lastCode();

        $this->reset($user->email, $code, 'short-pass-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
        $this->reset($user->email, $code, 'password')->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->reset($user->email, $code, 'a-much-longer-passphrase')->assertOk();
    }

    public function test_no_password_broker_points_at_a_missing_table(): void
    {
        foreach (config('auth.passwords', []) as $name => $broker) {
            if (isset($broker['table'])) {
                $this->assertTrue(Schema::hasTable($broker['table']), "Password broker {$name} uses missing table {$broker['table']}.");
            }
        }

        $this->assertNull(config('auth.defaults.passwords'));
        $this->assertNull(config('auth.passwords.users'));
    }
}
