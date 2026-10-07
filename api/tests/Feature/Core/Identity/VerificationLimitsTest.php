<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Services\Challenges;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Tenancy\TenantContext;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-10 applied to one-time codes: send limits, a failed-attempt budget
// across challenges, and no renewal of exhausted challenges.
class VerificationLimitsTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function signUp(): string
    {
        return $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Amina Otieno',
            'email' => 'amina@example.com',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => 'Amina Stores',
        ])->assertCreated()->json('challenge_id');
    }

    private function verify(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $code]);
    }

    private function resend(string $challengeId): TestResponse
    {
        return $this->postJson('/api/v1/auth/verify/resend', ['challenge_id' => $challengeId]);
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_guessing_across_resent_challenges_hits_the_hourly_failure_cap(): void
    {
        $challenge = $this->signUp();
        $failures = 0;

        // Four wrong guesses per challenge, then a new code, and again.
        while ($failures < Challenges::MAX_FAILURES_PER_HOUR) {
            $code = $this->lastCode();

            for ($i = 0; $i < 4 && $failures < Challenges::MAX_FAILURES_PER_HOUR; $i++, $failures++) {
                $this->verify($challenge, $this->wrong($code))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
            }

            if ($failures < Challenges::MAX_FAILURES_PER_HOUR) {
                $this->travel(61)->seconds();
                $challenge = $this->resend($challenge)->assertOk()->json('challenge_id');
            }
        }

        // Even the right code is refused now.
        $this->verify($challenge, $this->lastCode())
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        // The next window allows it again (with a fresh code: the old one expired).
        $this->travel(61)->minutes();
        $challenge = $this->resend($challenge)->assertOk()->json('challenge_id');
        $this->verify($challenge, $this->lastCode())->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_exhausted_challenge_cannot_be_resent(): void
    {
        $challenge = $this->signUp();
        $code = $this->lastCode();

        for ($i = 0; $i < Challenges::MAX_ATTEMPTS; $i++) {
            $this->verify($challenge, $this->wrong($code))->assertStatus(422);
        }

        $this->travel(61)->seconds();

        $this->resend($challenge)
            ->assertStatus(422)
            ->assertJsonPath('code', 'challenge_exhausted')
            ->assertJsonPath('message', __('auth.code.exhausted'));

        // Signing in, which needs the password, issues a new code.
        $newChallenge = $this->signIn('amina@example.com')->assertForbidden()->json('challenge_id');
        $this->assertNotNull($newChallenge);
        $this->verify($newChallenge, $this->lastCode())->assertOk();
    }

    public function test_a_resend_inside_sixty_seconds_is_refused(): void
    {
        $challenge = $this->signUp();

        $this->travel(30)->seconds();

        $response = $this->resend($challenge)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThanOrEqual(29, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(30, (int) $response->headers->get('Retry-After'));

        // The refused resend did not consume the challenge.
        $this->verify($challenge, $this->lastCode())->assertOk();
    }

    public function test_at_most_five_codes_an_hour(): void
    {
        $challenge = $this->signUp();

        for ($sent = 1; $sent < Challenges::MAX_SENDS_PER_HOUR; $sent++) {
            $this->travel(61)->seconds();
            $challenge = $this->resend($challenge)->assertOk()->json('challenge_id');
        }

        $this->travel(61)->seconds();
        $this->resend($challenge)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $this->travel(61)->minutes();
        $this->resend($challenge)->assertOk();
    }

    public function test_an_unverified_sign_in_inside_the_cooldown_keeps_the_code_already_sent(): void
    {
        $challenge = $this->signUp();
        $code = $this->lastCode();

        $this->signIn('amina@example.com')
            ->assertForbidden()
            ->assertJsonPath('code', 'unverified')
            ->assertJsonPath('challenge_id', null);

        $this->verify($challenge, $code)->assertOk();
    }

    public function test_a_new_challenge_consumes_the_earlier_open_ones(): void
    {
        $first = $this->signUp();
        $firstCode = $this->lastCode();

        $this->travel(61)->seconds();
        $second = $this->signIn('amina@example.com')->assertForbidden()->json('challenge_id');

        $this->assertNotNull(VerificationChallenge::findOrFail($first)->consumed_at);
        $this->verify($first, $firstCode)->assertStatus(422);
        $this->verify($second, $this->lastCode())->assertOk();
    }

    public function test_a_failed_delivery_does_not_fail_sign_up(): void
    {
        Notification::swap(new ChannelManager($this->app));

        $sender = new class implements SmsSender
        {
            public bool $down = true;

            public array $sent = [];

            public function send(string $to, string $message): void
            {
                if ($this->down) {
                    throw new RuntimeException('SMS gateway down');
                }

                $this->sent[] = $to;
            }
        };
        $this->app->instance(SmsSender::class, $sender);

        $response = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Amina Otieno',
            'phone' => '+254712345678',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => 'Amina Stores',
        ])->assertCreated();

        app(TenantContext::class)->set(VerificationChallenge::findOrFail($response->json('challenge_id'))->tenant_id);
        $this->assertSame('pending', User::sole()->status);

        // The user can ask again once delivery works.
        $sender->down = false;
        $this->travel(61)->seconds();
        $this->resend($response->json('challenge_id'))->assertOk();
        $this->assertSame(['+254712345678'], $sender->sent);
    }
}
