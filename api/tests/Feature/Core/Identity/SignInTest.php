<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-01: password sign-in by email or phone; TEN-01: the token sets the tenant.
class SignInTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function audits(User $user, string $action)
    {
        return $this->asTenant($user->tenant_id, fn () => AuditEntry::where('action', $action)->get());
    }

    public function test_the_correct_password_returns_a_token_and_is_audited_with_the_user(): void
    {
        $user = $this->createUser();

        $this->signIn($user->email)
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'locale', 'status', 'tenant_id']])
            ->assertJsonPath('user.id', $user->id);

        $entries = $this->audits($user, 'auth.sign_in');
        $this->assertCount(1, $entries);
        $this->assertSame($user->id, $entries[0]->user_id);
        $this->assertSame($user->id, $entries[0]->auditable_id);
    }

    public function test_email_sign_in_ignores_case(): void
    {
        $user = $this->createUser(['email' => 'mixed@example.com']);

        $this->signIn('Mixed@Example.com')->assertOk();
    }

    public function test_a_wrong_password_returns_422_and_is_audited(): void
    {
        $user = $this->createUser();

        $this->signIn($user->email, 'not-the-password')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonPath('message', __('auth.failed'))
            ->assertJsonPath('errors.login.0', __('auth.failed'));

        // The actor is unauthenticated; the subject is the account tried.
        $entries = $this->audits($user, 'auth.sign_in_failed');
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]->user_id);
        $this->assertSame($user->id, $entries[0]->auditable_id);
    }

    public function test_an_unknown_login_gets_the_same_answer(): void
    {
        $this->signIn('nobody@example.com')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonPath('message', __('auth.failed'));
    }

    public function test_an_unverified_user_is_refused_with_a_new_code(): void
    {
        $user = $this->createUser(['status' => 'pending', 'email_verified_at' => null]);

        $response = $this->signIn($user->email)
            ->assertForbidden()
            ->assertJsonPath('code', 'unverified')
            ->assertJsonPath('message', __('auth.unverified'))
            ->assertJsonMissingPath('token');

        $this->assertNotNull(VerificationChallenge::find($response->json('challenge_id')));
    }

    public function test_a_deactivated_user_is_refused(): void
    {
        $user = $this->createUser(['status' => 'deactivated']);

        $this->signIn($user->email)
            ->assertForbidden()
            ->assertJsonPath('code', 'deactivated')
            ->assertJsonPath('message', __('auth.deactivated'))
            ->assertJsonMissingPath('token');
    }

    public function test_a_deactivated_user_with_a_wrong_password_learns_nothing(): void
    {
        $user = $this->createUser(['status' => 'deactivated']);

        $this->signIn($user->email, 'not-the-password')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_sign_in_by_phone_in_local_or_international_form(): void
    {
        $user = $this->createUser(['email' => null, 'phone' => '+254712345678', 'phone_verified_at' => now(), 'email_verified_at' => null]);

        $this->signIn('+254712345678')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->signIn('0712 345 678')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_a_congolese_local_number_signs_in(): void
    {
        $user = $this->createUser(['email' => null, 'phone' => '+243812345678', 'phone_verified_at' => now(), 'email_verified_at' => null]);

        $this->signIn('0812345678')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_each_token_resolves_its_own_tenant_on_a_reused_application(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $tokenA = $this->tokenFor($a);
        $tokenB = $this->tokenFor($b);

        $this->getJson('/api/v1/me', $this->bearer($tokenA))
            ->assertOk()
            ->assertJsonPath('data.id', $a->id)
            ->assertJsonPath('data.tenant_id', $a->tenant_id);

        $this->getJson('/api/v1/me', $this->bearer($tokenB))
            ->assertOk()
            ->assertJsonPath('data.id', $b->id)
            ->assertJsonPath('data.tenant_id', $b->tenant_id);

        // Without a token the next request has no tenant and no user.
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_sign_out_revokes_the_token_and_is_audited(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);

        $this->postJson('/api/v1/auth/sign-out', [], $this->bearer($token))->assertNoContent();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();

        $entries = $this->audits($user, 'auth.sign_out');
        $this->assertCount(1, $entries);
        $this->assertSame($user->id, $entries[0]->user_id);
    }

    public function test_unauthenticated_requests_get_the_error_envelope(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => __('core.errors.unauthenticated'), 'code' => 'unauthenticated']);
    }

    public function test_malformed_tokens_are_unauthenticated(): void
    {
        foreach (['not-a-uuid|abc', 'abc', '0190a0a0-0000-7000-8000-000000000000|abc'] as $token) {
            $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
        }
    }

    public function test_a_confirmed_two_factor_user_gets_a_challenge_instead_of_a_token(): void
    {
        $user = $this->createUser(['phone' => '+254700000001']);
        $this->asTenant($user->tenant_id, fn () => $user->forceFill([
            'two_factor_method' => 'sms',
            'two_factor_confirmed_at' => now(),
        ])->save());

        $response = $this->signIn($user->email)
            ->assertOk()
            ->assertJsonPath('status', 'two_factor_required')
            ->assertJsonMissingPath('token');

        $challenge = VerificationChallenge::findOrFail($response->json('challenge_id'));
        $this->assertSame('two_factor', $challenge->purpose);
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $this->postJson('/api/v1/auth/sign-in', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', __('core.errors.validation_failed'))
            ->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_unknown_routes_use_the_envelope(): void
    {
        $this->getJson('/api/v1/nothing-here')->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
