<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Services\Challenges;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-03: TOTP and SMS two-factor, enrolment, the sign-in challenge, and
// enrol-only tokens for users whose role requires a second factor.
class TwoFactorTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** The TOTP for $secret at the application's clock (which tests can move). */
    private function otp(string $secret): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }

    private function fresh(User $user): User
    {
        return $this->asTenant($user->tenant_id, fn () => User::findOrFail($user->id));
    }

    private function audits(User $user, string $action)
    {
        return $this->asTenant($user->tenant_id, fn () => AuditEntry::where('action', $action)->get());
    }

    private function complete(string $challengeId, string $code, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_id' => $challengeId, 'code' => $code], $headers);
    }

    /** Enrol $user in TOTP through the API; returns the secret. */
    private function enrolTotp(User $user, ?string $token = null): string
    {
        $token ??= $this->tokenFor($user);
        $secret = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))->assertOk()->json('secret');
        $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)], $this->bearer($token))
            ->assertOk();

        return $secret;
    }

    /** Give $user the tenant's Owner role (RBAC-03) and make that role require two-factor. */
    private function requireTwoFactorForOwner(User $user): void
    {
        $this->asTenant($user->tenant_id, function () use ($user) {
            $owner = app(RoleTemplates::class)->provision(Tenant::findOrFail($user->tenant_id))->get('owner');
            RoleAssignment::create(['user_id' => $user->id, 'role_id' => $owner->id, 'scope_type' => Scope::TENANT]);
            // System roles are read-only through the model (RBAC-02).
            DB::table('roles')->where('id', $owner->id)->update(['requires_two_factor' => true]);
        });
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_starting_totp_enrolment_returns_the_secret_url_and_qr_code(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($this->tokenFor($user)))
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg']);

        $this->assertStringStartsWith('otpauth://totp/', $response->json('otpauth_url'));
        $this->assertStringContainsString('<svg', $response->json('qr_svg'));

        $fresh = $this->fresh($user);
        $this->assertSame($response->json('secret'), $fresh->two_factor_secret);
        $this->assertSame('totp', $fresh->two_factor_method);
        $this->assertNull($fresh->two_factor_confirmed_at);
        // Stored encrypted.
        $raw = $this->asTenant($user->tenant_id, fn () => DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->assertNotSame($response->json('secret'), $raw);
    }

    public function test_totp_enrolment_then_sign_in_needs_the_second_factor(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);

        $this->assertNotNull($this->fresh($user)->two_factor_confirmed_at);
        $this->assertCount(1, $this->audits($user, 'auth.two_factor_enabled'));

        $challengeId = $this->signIn($user->email)
            ->assertOk()
            ->assertJsonPath('status', 'two_factor_required')
            ->assertJsonMissingPath('token')
            ->json('challenge_id');
        $this->assertNull(VerificationChallenge::findOrFail($challengeId)->channel);

        $this->travel(31)->seconds();
        $before = $this->audits($user, 'auth.sign_in')->count();

        $token = $this->complete($challengeId, $this->otp($secret), ['User-Agent' => 'Phone'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.two_factor_enabled', true)
            ->json('token');

        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/me/permissions', $this->bearer($token))->assertOk();
        $this->assertCount($before + 1, $this->audits($user, 'auth.sign_in'));
    }

    public function test_an_invalid_code_does_not_complete_the_sign_in(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);
        $challengeId = $this->signIn($user->email)->json('challenge_id');
        $this->travel(31)->seconds();

        $this->complete($challengeId, $this->wrong($this->otp($secret)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_code')
            ->assertJsonMissingPath('token');
        $this->complete('not-a-uuid', $this->otp($secret))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_a_challenge_allows_five_attempts(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);
        $challengeId = $this->signIn($user->email)->json('challenge_id');
        $this->travel(31)->seconds();

        for ($i = 0; $i < Challenges::MAX_ATTEMPTS; $i++) {
            $this->complete($challengeId, $this->wrong($this->otp($secret)))->assertStatus(422);
        }

        $this->complete($challengeId, $this->otp($secret))
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.code.attempts'));
    }

    public function test_a_totp_code_cannot_be_used_twice(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);

        // The code that confirmed enrolment is still current, but spent.
        $challengeId = $this->signIn($user->email)->json('challenge_id');
        $this->complete($challengeId, app(Google2FA::class)->getCurrentOtp($secret))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_code');
    }

    public function test_a_code_from_the_previous_step_is_accepted_once(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);
        $this->travel(2)->minutes();

        $previous = app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) - 1);
        $challengeId = $this->signIn($user->email)->json('challenge_id');
        $this->complete($challengeId, $previous)->assertOk();
    }

    public function test_an_expired_two_factor_challenge_cannot_be_used(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);
        $challengeId = $this->signIn($user->email)->json('challenge_id');

        $this->travel(Challenges::TTL_MINUTES + 1)->minutes();

        $this->complete($challengeId, $this->otp($secret))->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertNull(app(Challenges::class)->findOpen($challengeId, VerificationChallenge::PURPOSE_TWO_FACTOR));
    }

    public function test_only_contact_verification_challenges_stay_open_for_resend_once_expired(): void
    {
        $user = $this->createUser(['phone' => '+254700000001', 'phone_verified_at' => now()]);
        $challenges = app(Challenges::class);

        $ids = $this->asTenant($user->tenant_id, fn () => [
            VerificationChallenge::PURPOSE_VERIFY_CONTACT => $challenges->create($user, VerificationChallenge::PURPOSE_VERIFY_CONTACT, 'email', $user->email)[0]->id,
            VerificationChallenge::PURPOSE_PASSWORD_RESET => $challenges->create($user, VerificationChallenge::PURPOSE_PASSWORD_RESET, 'email', $user->email)[0]->id,
            VerificationChallenge::PURPOSE_TWO_FACTOR => $challenges->create($user, VerificationChallenge::PURPOSE_TWO_FACTOR, null, null)[0]->id,
        ]);

        $this->travel(Challenges::TTL_MINUTES + 1)->minutes();

        $this->assertNotNull($challenges->findOpen($ids[VerificationChallenge::PURPOSE_VERIFY_CONTACT], VerificationChallenge::PURPOSE_VERIFY_CONTACT));
        $this->assertNull($challenges->findOpen($ids[VerificationChallenge::PURPOSE_PASSWORD_RESET], VerificationChallenge::PURPOSE_PASSWORD_RESET));
        $this->assertNull($challenges->findOpen($ids[VerificationChallenge::PURPOSE_TWO_FACTOR], VerificationChallenge::PURPOSE_TWO_FACTOR));
    }

    public function test_a_wrong_enrolment_code_does_not_enable_two_factor(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);
        $secret = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))->json('secret');

        $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => $this->wrong($this->otp($secret))], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_code');

        $this->assertNull($this->fresh($user)->two_factor_confirmed_at);
        $this->signIn($user->email)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_enrolment_guesses_count_against_the_hourly_failure_cap(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);
        $secret = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))->json('secret');

        for ($i = 0; $i < Challenges::MAX_FAILURES_PER_HOUR; $i++) {
            $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => $this->wrong($this->otp($secret))], $this->bearer($token))
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => $this->otp($secret)], $this->bearer($token))
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_confirming_without_starting_is_refused(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => '123456'], $this->bearer($this->tokenFor($user)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'two_factor_not_started');
    }

    public function test_starting_again_replaces_an_unconfirmed_secret(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);

        $first = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))->json('secret');
        $second = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))->json('secret');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->fresh($user)->two_factor_secret);
    }

    public function test_an_enrolled_user_must_disable_before_enrolling_again(): void
    {
        $user = $this->createUser();
        $this->enrolTotp($user);

        $this->travel(31)->seconds();
        $challengeId = $this->signIn($user->email)->json('challenge_id');
        $token = $this->complete($challengeId, $this->otp($this->fresh($user)->two_factor_secret))->json('token');

        $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))
            ->assertStatus(409)
            ->assertJsonPath('code', 'two_factor_already_enabled');
        $this->postJson('/api/v1/me/two-factor/sms', [], $this->bearer($token))
            ->assertStatus(409)
            ->assertJsonPath('code', 'two_factor_already_enabled');
    }

    public function test_sms_two_factor_sends_a_code_and_accepts_it(): void
    {
        $user = $this->createUser(['phone' => '+254712345678', 'phone_verified_at' => now()]);
        $token = $this->tokenFor($user);

        $this->postJson('/api/v1/me/two-factor/sms', [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('destination_masked', fn ($masked) => is_string($masked) && ! str_contains($masked, '712345'));
        Notification::assertSentTo($user, VerificationCode::class, fn (VerificationCode $n) => $n->channel === 'sms' && $n->purpose === 'two_factor');

        $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => $this->wrong($this->lastCode())], $this->bearer($token))
            ->assertStatus(422);
        $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => $this->lastCode()], $this->bearer($token))
            ->assertOk();

        $fresh = $this->fresh($user);
        $this->assertSame('sms', $fresh->two_factor_method);
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertCount(1, $this->audits($user, 'auth.two_factor_enabled'));

        // Sign-in sends a new code by SMS; it completes the sign-in once.
        $this->travel(61)->seconds();
        $challengeId = $this->signIn($user->email)->assertJsonPath('status', 'two_factor_required')->json('challenge_id');
        $this->assertSame('sms', VerificationChallenge::findOrFail($challengeId)->channel);
        $code = $this->lastCode();

        $this->complete($challengeId, $this->wrong($code))->assertStatus(422);
        $this->complete($challengeId, $code)->assertOk()->assertJsonStructure(['token', 'user']);
        $this->complete($challengeId, $code)->assertStatus(422);
    }

    public function test_sms_two_factor_needs_a_verified_phone(): void
    {
        $noPhone = $this->createUser();
        $unverified = $this->createUser(['phone' => '+254712345679', 'phone_verified_at' => null]);

        foreach ([$noPhone, $unverified] as $user) {
            $this->postJson('/api/v1/me/two-factor/sms', [], $this->bearer($this->tokenFor($user)))
                ->assertStatus(422)
                ->assertJsonPath('code', 'phone_required');
        }

        Notification::assertNothingSentTo($unverified, VerificationCode::class);
    }

    public function test_disabling_requires_the_correct_password(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);
        $this->enrolTotp($user, $token);

        $this->deleteJson('/api/v1/me/two-factor', ['password' => 'not-the-password'], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', __('auth.password.incorrect'));
        $this->assertNotNull($this->fresh($user)->two_factor_confirmed_at);

        $this->deleteJson('/api/v1/me/two-factor', ['password' => $this->password], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('message', __('auth.two_factor.disabled'));

        $fresh = $this->fresh($user);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_method);
        $this->assertCount(1, $this->audits($user, 'auth.two_factor_disabled'));

        $this->signIn($user->email)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_deactivated_user_cannot_complete_the_challenge(): void
    {
        $user = $this->createUser();
        $secret = $this->enrolTotp($user);
        $challengeId = $this->signIn($user->email)->json('challenge_id');
        $this->asTenant($user->tenant_id, fn () => User::findOrFail($user->id)->forceFill(['status' => 'deactivated'])->save());
        $this->travel(31)->seconds();

        $this->complete($challengeId, $this->otp($secret))->assertForbidden()->assertJsonPath('code', 'deactivated');
    }

    public function test_two_factor_is_not_required_without_a_role_that_requires_it(): void
    {
        $user = $this->createUser();

        $this->assertFalse($this->asTenant($user->tenant_id, fn () => app(TwoFactor::class)->required($user)));
        $this->signIn($user->email)->assertOk()->assertJsonPath('two_factor_enrollment_required', false);
    }

    public function test_a_role_that_requires_two_factor_limits_the_token_to_enrolment(): void
    {
        $user = $this->createUser();
        $this->requireTwoFactorForOwner($user);

        $this->assertTrue($this->asTenant($user->tenant_id, fn () => app(TwoFactor::class)->required($user)));

        $token = $this->signIn($user->email)
            ->assertOk()
            ->assertJsonPath('two_factor_enrollment_required', true)
            ->json('token');

        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/me/permissions', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_enrollment_required')
            ->assertJsonPath('message', __('auth.two_factor.enrollment_required'));
        $this->getJson('/api/v1/auth/sessions', $this->bearer($token))->assertForbidden();
        $this->deleteJson('/api/v1/me/two-factor', ['password' => $this->password], $this->bearer($token))->assertForbidden();

        // Enrolling lifts the limit on this token.
        $secret = $this->postJson('/api/v1/me/two-factor/totp', [], $this->bearer($token))->assertOk()->json('secret');
        $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => $this->otp($secret)], $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/me/permissions', $this->bearer($token))->assertOk();
    }

    public function test_an_enrolment_only_token_can_sign_out(): void
    {
        $user = $this->createUser();
        $this->requireTwoFactorForOwner($user);

        $token = $this->signIn($user->email)->json('token');

        $this->postJson('/api/v1/auth/sign-out', [], $this->bearer($token))->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }
}
