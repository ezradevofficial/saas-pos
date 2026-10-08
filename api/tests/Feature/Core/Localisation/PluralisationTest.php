<?php

namespace Tests\Feature\Core\Localisation;

use App\Core\Http\ApiErrorRenderer;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Services\LoginThrottle;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Traits\Localizable;
use Tests\TestCase;

// L10N-01: counts of seconds and minutes agree in English and French.
class PluralisationTest extends TestCase
{
    use Localizable;

    public function test_retry_after_seconds(): void
    {
        $this->withLocale('en', function () {
            $this->assertSame('Too many requests. Try again in 1 second.', ApiErrorRenderer::retryMessage(1));
            $this->assertSame('Too many requests. Try again in 2 seconds.', ApiErrorRenderer::retryMessage(2));
        });
        $this->withLocale('fr', function () {
            $this->assertSame('Trop de requêtes. Réessayez dans 1 seconde.', ApiErrorRenderer::retryMessage(1));
            $this->assertSame('Trop de requêtes. Réessayez dans 2 secondes.', ApiErrorRenderer::retryMessage(2));
        });
    }

    public function test_locked_minutes_round_up(): void
    {
        $this->withLocale('en', function () {
            $this->assertSame('Too many failed sign-ins. Try again in 1 minute.', LoginThrottle::lockedMessage(30));
            $this->assertSame('Too many failed sign-ins. Try again in 2 minutes.', LoginThrottle::lockedMessage(61));
        });
        $this->withLocale('fr', function () {
            $this->assertSame('Trop d’échecs de connexion. Réessayez dans 1 minute.', LoginThrottle::lockedMessage(60));
            $this->assertSame('Trop d’échecs de connexion. Réessayez dans 2 minutes.', LoginThrottle::lockedMessage(120));
        });
    }

    public function test_code_expiry_in_mail_and_sms(): void
    {
        $sms = fn (int $minutes) => (new VerificationCode('123456', 'sms', 'two_factor', $minutes))->toSms(new AnonymousNotifiable);

        $this->withLocale('en', function () use ($sms) {
            $this->assertStringContainsString('It expires in 1 minute. Never', $sms(1));
            $this->assertStringContainsString('It expires in 2 minutes. Never', $sms(2));
            $this->assertSame('It expires in 1 minute and works once.', trans_choice('auth.notifications.verification_code.expiry', 1, ['minutes' => 1]));
        });
        $this->withLocale('fr', function () use ($sms) {
            $this->assertStringContainsString('Il expire dans 1 minute. Ne', $sms(1));
            $this->assertStringContainsString('Il expire dans 2 minutes. Ne', $sms(2));
            $this->assertSame('Il expire dans 2 minutes et ne fonctionne qu’une fois.', trans_choice('auth.notifications.verification_code.expiry', 2, ['minutes' => 2]));
        });
    }

    public function test_a_429_response_uses_the_plural_form(): void
    {
        $this->withLocale('en', fn () => $this->assertStringNotContainsString('|', ApiErrorRenderer::retryMessage(60)));
    }
}
