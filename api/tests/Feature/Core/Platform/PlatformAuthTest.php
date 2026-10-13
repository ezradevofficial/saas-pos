<?php

namespace Tests\Feature\Core\Platform;

use App\Core\Platform\Models\PlatformAccessToken;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformAuth;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * ADR 013, SUB-01, AUD-01: platform staff sign in with a password and a
 * required TOTP code; staff and tenant tokens never cross; every staff
 * action is audited in an append-only, hash-chained log.
 */
class PlatformAuthTest extends TestCase
{
    use CreatesIdentities, CreatesPlatformStaff, RefreshTenantDatabase, WithoutOwnerConnection;

    private function setupToken(string $output): string
    {
        $this->assertMatchesRegularExpression('#/platform/setup/([A-Za-z0-9]{48})#', $output);
        preg_match('#/platform/setup/([A-Za-z0-9]{48})#', $output, $m);

        return $m[1];
    }

    public function test_the_command_creates_an_account_whose_setup_needs_two_factor_before_anything_works(): void
    {
        // The command needs only the runtime connection (global tables).
        $this->withoutOwnerConnection();

        $this->assertSame(0, Artisan::call('platform:user', ['email' => 'Wanjiru@Example.com', '--name' => 'Wanjiru Kamau']));
        $output = Artisan::output();
        $token = $this->setupToken($output);
        $this->assertStringContainsString(config('app.frontend_url').'/platform/setup/'.$token, $output);

        $user = PlatformUser::where('email', 'wanjiru@example.com')->firstOrFail();
        $this->assertSame([PlatformUser::STATUS_PENDING, 'owner', null], [$user->status, $user->role, $user->password]);
        $this->assertNotSame($token, $user->getRawOriginal('setup_token_hash'), 'only a hash of the link is stored');

        $this->getJson("/api/v1/platform/auth/setup/{$token}")->assertOk()->assertExactJson(['data' => ['name' => 'Wanjiru Kamau', 'email' => 'wanjiru@example.com']]);

        // A weak or unconfirmed password is refused.
        $this->postJson("/api/v1/platform/auth/setup/{$token}", ['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $setup = $this->postJson("/api/v1/platform/auth/setup/{$token}", ['password' => $this->staffPassword, 'password_confirmation' => $this->staffPassword])
            ->assertOk()
            ->assertJsonPath('status', 'enrolment_required')
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role'], 'two_factor' => ['secret', 'otpauth_url', 'qr_svg']]);
        $bearer = ['Authorization' => 'Bearer '.$setup->json('token')];
        $secret = $setup->json('two_factor.secret');
        $this->assertStringStartsWith('plat_', $setup->json('token'));

        // The link works once.
        $this->getJson("/api/v1/platform/auth/setup/{$token}")->assertNotFound()->assertJsonPath('code', 'setup_link_invalid');

        // Until two-factor is confirmed, the token reaches only enrolment.
        $this->getJson('/api/v1/platform/me', $bearer)->assertOk()->assertJsonPath('data.two_factor_enabled', false)->assertJsonPath('data.abilities', []);
        $this->getJson('/api/v1/platform/catalogue/modules', $bearer)->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');
        // Signing in again before enrolment still gives an enrolment token only.
        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'wanjiru@example.com', 'password' => $this->staffPassword])
            ->assertOk()->assertJsonPath('status', 'enrolment_required');

        $this->postJson('/api/v1/platform/me/two-factor/totp/confirm', ['code' => '000000'], $bearer)->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
        $this->postJson('/api/v1/platform/me/two-factor/totp/confirm', ['code' => $this->totp($secret)], $bearer)
            ->assertOk()->assertJsonPath('user.two_factor_enabled', true)->assertJsonPath('user.status', 'active');

        $this->getJson('/api/v1/platform/catalogue/modules', $bearer)->assertOk();
        $this->postJson('/api/v1/platform/me/two-factor/totp', [], $bearer)->assertConflict();

        $actions = DB::table('platform_audit_logs')->orderBy('seq')->pluck('action')->all();
        $this->assertSame(['platform.user.created', 'platform.auth.setup_completed', 'platform.auth.enrolment_sign_in', 'platform.auth.sign_in_failed', 'platform.auth.two_factor_enabled'], $actions);
        $this->assertNull(app(PlatformAuditor::class)->verify());
    }

    public function test_an_existing_account_is_refused_unless_reset(): void
    {
        $user = $this->staff();
        $old = $this->staffBearer($user);

        $this->assertSame(1, Artisan::call('platform:user', ['email' => $user->email]));
        $this->assertSame(1, Artisan::call('platform:user', ['email' => 'not-an-email']));
        $this->assertSame(1, Artisan::call('platform:user', ['email' => 'x@example.com', '--role' => 'god']));

        $this->assertSame(0, Artisan::call('platform:user', ['email' => $user->email, '--reset' => true, '--role' => 'support']));
        $this->setupToken(Artisan::output());
        $user->refresh();
        $this->assertSame([PlatformUser::STATUS_PENDING, 'support', null, null], [$user->status, $user->role, $user->password, $user->two_factor_confirmed_at]);
        $this->getJson('/api/v1/platform/me', $old)->assertUnauthorized();
    }

    public function test_sign_in_needs_the_password_then_a_totp_code_that_works_once(): void
    {
        $user = $this->staff(attributes: ['email' => 'amani@example.com']);

        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'amani@example.com', 'password' => 'wrong-password-123'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'nobody@example.com', 'password' => 'wrong-password-123'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_credentials');

        $challenge = $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'AMANI@example.com', 'password' => $this->staffPassword])
            ->assertOk()->assertJsonPath('status', 'two_factor_required')->assertJsonMissingPath('token')->json('challenge');

        $this->postJson('/api/v1/platform/auth/two-factor', ['challenge' => 'forged', 'code' => $this->totp($user->two_factor_secret)])
            ->assertUnprocessable()->assertJsonPath('code', 'challenge_expired');
        $this->postJson('/api/v1/platform/auth/two-factor', ['challenge' => $challenge, 'code' => '123456'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_code');

        $code = $this->totp($user->two_factor_secret);
        $signedIn = $this->postJson('/api/v1/platform/auth/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('status', 'signed_in')->assertJsonPath('user.email', 'amani@example.com');
        $this->getJson('/api/v1/platform/catalogue/modules', ['Authorization' => 'Bearer '.$signedIn->json('token')])->assertOk();
        $this->assertNotNull($user->refresh()->last_sign_in_at);

        // The same code is never accepted twice.
        $this->postJson('/api/v1/platform/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();

        // An expired challenge is refused.
        $this->travel(PlatformAuth::CHALLENGE_MINUTES + 1)->minutes();
        $this->postJson('/api/v1/platform/auth/two-factor', ['challenge' => $challenge, 'code' => $this->totp($user->two_factor_secret)])
            ->assertUnprocessable()->assertJsonPath('code', 'challenge_expired');

        // Signing out ends that session.
        $this->travelBack();
        $bearer = $this->staffBearer($user);
        $this->postJson('/api/v1/platform/auth/sign-out', [], $bearer)->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/platform/me', $bearer)->assertUnauthorized();

        $this->assertContains('platform.auth.sign_in', DB::table('platform_audit_logs')->pluck('action')->all());
        $this->assertNull(app(PlatformAuditor::class)->verify());
    }

    public function test_five_failures_lock_the_account_for_fifteen_minutes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->staff(attributes: ['email' => 'lock@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'lock@example.com', 'password' => 'wrong-password-123'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'lock@example.com', 'password' => $this->staffPassword])
            ->assertStatus(423)->assertJsonPath('code', 'locked')->assertHeader('Retry-After');

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'lock@example.com', 'password' => $this->staffPassword])->assertOk();
        $this->assertSame(0, $user->refresh()->failed_sign_ins);
    }

    public function test_sign_in_is_rate_limited_per_email_and_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'flood@example.com', 'password' => 'x'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'flood@example.com', 'password' => 'x'])->assertTooManyRequests();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/platform/auth/sign-in', ['email' => "other{$i}@example.com", 'password' => 'x'])->assertUnprocessable();
        }
        // 10 a minute per address in all.
        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => 'fresh@example.com', 'password' => 'x'])->assertTooManyRequests();
    }

    public function test_platform_and_tenant_tokens_never_cross(): void
    {
        $tenantUser = $this->createUser();
        $tenantBearer = $this->bearer($this->tokenFor($tenantUser));
        $staffBearer = $this->staffBearer($this->staff());

        // A tenant Owner's token on every platform route: unauthenticated.
        foreach (['/api/v1/platform/me', '/api/v1/platform/catalogue/modules', '/api/v1/platform/catalogue/coupons'] as $uri) {
            $this->getJson($uri, $tenantBearer)->assertUnauthorized();
            $this->app['auth']->forgetGuards();
        }
        $this->postJson('/api/v1/platform/catalogue/coupons', ['code' => 'HIJACK', 'kind' => 'percent', 'percent' => 90], $tenantBearer)->assertUnauthorized();
        $this->assertDatabaseMissing('catalogue_coupons', ['code' => 'HIJACK']);

        // A staff token on tenant routes: unauthenticated, and no tenant is set.
        foreach (['/api/v1/me', '/api/v1/companies', '/api/v1/me/permissions'] as $uri) {
            $this->app['auth']->forgetGuards();
            $this->getJson($uri, $staffBearer)->assertUnauthorized();
        }

        // Controls: each token works on its own API.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $tenantBearer)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/platform/me', $staffBearer)->assertOk();
    }

    public function test_deactivated_staff_and_expired_tokens_are_refused(): void
    {
        $user = $this->staff();
        $bearer = $this->staffBearer($user);
        $this->getJson('/api/v1/platform/me', $bearer)->assertOk();

        $this->travel(13)->hours();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/platform/me', $bearer)->assertUnauthorized();
        $this->travelBack();

        $bearer = $this->staffBearer($user);
        $user->forceFill(['status' => PlatformUser::STATUS_DEACTIVATED])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/platform/me', $bearer)->assertUnauthorized();
        $this->postJson('/api/v1/platform/auth/sign-in', ['email' => $user->email, 'password' => $this->staffPassword])->assertForbidden()->assertJsonPath('code', 'deactivated');
    }

    public function test_a_full_token_without_confirmed_two_factor_is_refused(): void
    {
        // Defence in depth: even a full token is refused once two-factor is gone.
        $user = $this->staff(attributes: ['two_factor_confirmed_at' => null]);
        $this->getJson('/api/v1/platform/catalogue/modules', $this->staffBearer($user, [PlatformAccessToken::FULL]))->assertForbidden();
    }

    public function test_the_platform_audit_log_is_append_only(): void
    {
        $this->staff();
        app(PlatformAuditor::class)->record('platform.test', null, null, ['n' => 1]);
        app(PlatformAuditor::class)->record('platform.test', null, ['n' => 1], ['n' => 2]);
        $this->assertNull(app(PlatformAuditor::class)->verify());

        // The runtime role may not update or delete entries (and a trigger refuses every role).
        foreach (['update platform_audit_logs set action = ?', 'delete from platform_audit_logs where action <> ?'] as $sql) {
            $refused = false;
            try {
                DB::transaction(fn () => DB::statement($sql, ['platform.forged']));
            } catch (QueryException) {
                $refused = true;
            }
            $this->assertTrue($refused, "{$sql} should be refused");
        }
        $this->assertSame(2, DB::table('platform_audit_logs')->where('action', 'platform.test')->count());
    }
}
