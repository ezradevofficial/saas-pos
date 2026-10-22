<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\NullSmsSender;
use App\Core\Notifications\Sms\SmsAvailability;
use App\Core\Notifications\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * AUTH-01..AUTH-05, owner decision 2026-10-22: launch email-only. With no
 * SMS provider configured (SmsAvailability) sign-up, sign-in, two-factor,
 * password reset and the code resend refuse the phone channel with
 * `sms_unavailable`, in English and French, and nothing is sent. Phone-only
 * users who need no code still sign in; those who need one are told to have
 * an email address added. `public/branding` reports the mode to the apps.
 */
class EmailOnlyModeTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function emailOnly(): void
    {
        config(['services.sms.driver' => 'none']);
    }

    private function production(?string $driver): void
    {
        $this->app['env'] = 'production';
        config(['services.sms.driver' => $driver]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    private function assertRefused(TestResponse $response, ?string $field = null, string $key = 'auth.sms_unavailable.use_email'): void
    {
        $response->assertUnprocessable()->assertJsonPath('code', 'sms_unavailable')->assertJsonPath('message', __($key));

        if ($field !== null) {
            $response->assertJsonValidationErrors([$field => __($key)]);
        }
    }

    private function smsTwoFactorUser(array $attributes): User
    {
        $user = $this->createUser($attributes);
        $this->asTenant($user->tenant_id, fn () => $user->forceFill(['two_factor_method' => TwoFactor::METHOD_SMS, 'two_factor_confirmed_at' => now()])->save());

        return $user;
    }

    private function signUp(array $contact, string $locale = 'en'): TestResponse
    {
        return $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Amina Otieno',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => $locale,
            'business_name' => 'Amina Stores',
            ...$contact,
        ], ['Accept-Language' => $locale]);
    }

    public function test_sms_is_available_only_with_a_driver_this_release_implements(): void
    {
        $sms = app(SmsAvailability::class);

        // Testing: the log driver by default.
        $this->assertSame('log', $sms->driver());
        $this->assertTrue($sms->available());

        $this->emailOnly();
        $this->assertFalse($sms->available());

        // A real environment: the log driver never counts, nor an unknown name, nor nothing at all.
        foreach (['log', null, 'some-provider', 'none'] as $driver) {
            $this->production($driver);
            $this->assertFalse($sms->available(), var_export($driver, true));
            $this->app->forgetInstance(SmsSender::class);
            $this->assertInstanceOf(NullSmsSender::class, $this->app->make(SmsSender::class));
        }

        $this->app['env'] = 'testing';
        config(['services.sms.driver' => 'log']);
        $this->app->forgetInstance(SmsSender::class);
        $this->assertInstanceOf(LogSmsSender::class, $this->app->make(SmsSender::class));
    }

    public function test_the_public_meta_reports_whether_sms_is_available(): void
    {
        $this->getJson('/api/v1/public/branding')->assertOk()->assertJsonPath('meta.sms_available', true);

        $this->emailOnly();
        $this->getJson('/api/v1/public/branding?host=shop.example.com')->assertOk()->assertJsonPath('meta.sms_available', false);
    }

    public function test_a_phone_sign_up_is_refused_in_english_and_french_and_nothing_is_made(): void
    {
        $this->emailOnly();

        $this->assertRefused($this->signUp(['phone' => '0712345678']), 'phone');
        $french = $this->signUp(['phone' => '+254712345678'], 'fr')->assertUnprocessable()->assertJsonPath('code', 'sms_unavailable');
        $this->assertSame(__('auth.sms_unavailable.use_email', [], 'fr'), $french->json('message'));
        $this->assertNotSame(__('auth.sms_unavailable.use_email', [], 'en'), $french->json('message'));

        $this->assertNull(DB::selectOne('select auth_tenant_for_login(?) as tenant_id', ['+254712345678'])->tenant_id);
        Notification::assertNothingSent();

        // Email sign-up is unaffected.
        $this->signUp(['email' => 'amina@example.com'])->assertCreated();
        $this->assertNotNull(DB::selectOne('select auth_tenant_for_login(?) as tenant_id', ['amina@example.com'])->tenant_id);
    }

    public function test_a_password_reset_for_a_phone_number_is_refused_whether_or_not_it_is_known(): void
    {
        $this->createUser(['phone' => '+254712345678', 'phone_verified_at' => now()]);
        $this->emailOnly();

        foreach (['+254712345678', '+254799999999'] as $login) {
            $this->assertRefused($this->postJson('/api/v1/auth/password/forgot', ['login' => $login]), 'login');
        }
        Notification::assertNothingSent();

        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'someone@example.com'])->assertStatus(202);
    }

    public function test_an_active_phone_only_user_still_signs_in_with_the_password(): void
    {
        $this->createUser(['email' => null, 'email_verified_at' => null, 'phone' => '+254712345678', 'phone_verified_at' => now()]);
        $this->emailOnly();

        $this->signIn('+254712345678')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_pending_phone_only_user_is_told_to_have_an_email_added_and_gets_no_code(): void
    {
        $user = $this->createUser(['email' => null, 'email_verified_at' => null, 'phone' => '+254712345678', 'status' => User::STATUS_PENDING]);
        $this->emailOnly();

        $this->assertRefused($this->signIn('+254712345678'), key: 'auth.sms_unavailable.no_email');
        $this->assertSame(0, $this->asTenant($user->tenant_id, fn () => VerificationChallenge::count()));
        Notification::assertNothingSent();
    }

    public function test_a_pending_phone_only_user_still_gets_the_code_where_sms_is_available(): void
    {
        $user = $this->createUser(['email' => null, 'email_verified_at' => null, 'phone' => '+254712345678', 'status' => User::STATUS_PENDING]);

        $this->signIn('+254712345678')->assertStatus(403)->assertJsonPath('code', 'unverified');
        Notification::assertSentTo($user, VerificationCode::class, fn (VerificationCode $n) => $n->channel === 'sms');
    }

    public function test_an_sms_code_is_not_resent(): void
    {
        $user = $this->createUser(['email' => null, 'email_verified_at' => null, 'phone' => '+254712345678', 'status' => User::STATUS_PENDING]);
        $challengeId = $this->signIn('+254712345678')->json('challenge_id');
        $this->assertNotNull($challengeId);
        $this->travel(61)->seconds();
        $this->emailOnly();

        $this->assertRefused($this->postJson('/api/v1/auth/verify/resend', ['challenge_id' => $challengeId]), 'challenge_id', 'auth.sms_unavailable.no_email');
        Notification::assertSentToTimes($user, VerificationCode::class, 1);
    }

    public function test_sms_two_factor_enrolment_is_refused(): void
    {
        $user = $this->createUser(['phone' => '+254712345678', 'phone_verified_at' => now()]);
        $token = $this->tokenFor($user);
        $this->emailOnly();

        $this->assertRefused($this->postJson('/api/v1/me/two-factor/sms', [], $this->bearer($token)), key: 'auth.sms_unavailable.two_factor');
        $this->assertNull($this->asTenant($user->tenant_id, fn () => $user->fresh()->two_factor_method));
        Notification::assertNothingSent();
    }

    public function test_an_sms_two_factor_sign_in_sends_its_code_to_the_verified_email_instead(): void
    {
        $user = $this->smsTwoFactorUser(['phone' => '+254712345678', 'phone_verified_at' => now()]);
        $this->emailOnly();

        $challengeId = $this->signIn($user->email)->assertOk()->assertJsonPath('status', 'two_factor_required')->json('challenge_id');

        $this->assertSame('email', $this->asTenant($user->tenant_id, fn () => VerificationChallenge::findOrFail($challengeId)->channel));
        Notification::assertSentTo($user, VerificationCode::class, fn (VerificationCode $n) => $n->channel === 'email' && $n->purpose === 'two_factor');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_id' => $challengeId, 'code' => $this->lastCode()])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_sms_two_factor_sign_in_without_a_verified_email_is_refused_never_let_through(): void
    {
        $this->smsTwoFactorUser(['email' => null, 'email_verified_at' => null, 'phone' => '+254712345678', 'phone_verified_at' => now()]);
        $this->emailOnly();

        $response = $this->signIn('+254712345678');

        $this->assertRefused($response, key: 'auth.sms_unavailable.no_email');
        $response->assertJsonMissingPath('token');
        Notification::assertNothingSent();
    }
}
