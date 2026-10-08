<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\Notifications\InvitationNotification;
use App\Core\Identity\Notifications\NewDeviceSignIn;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Traits\Localizable;
use Tests\TestCase;

// L10N-01: mail boilerplate and dates follow the recipient's language.
class NotificationLocaleTest extends TestCase
{
    use Localizable;

    private function invitation(string $locale): InvitationNotification
    {
        return (new InvitationNotification(
            str_repeat('a', 40), 'mail', 'Amina', 'Amani Stores', 'Baraka',
            CarbonImmutable::parse('2026-10-12 14:30:00', 'UTC'),
        ))->locale($locale);
    }

    /** Renders as the notification channel does: in the notification's locale. */
    private function render(object $notification): string
    {
        return $this->withLocale($notification->locale, fn () => (string) $notification->toMail(new AnonymousNotifiable)->render());
    }

    public function test_a_french_invitation_has_french_boilerplate_and_date(): void
    {
        $html = html_entity_decode($this->render($this->invitation('fr')), ENT_QUOTES);

        $this->assertStringContainsString('12 oct. 2026 14:30 UTC', $html);
        $this->assertStringContainsString('Tous droits réservés.', $html);
        $this->assertStringContainsString('Si le bouton « ', $html);
        $this->assertStringContainsString('dans votre navigateur :', $html);
        $this->assertStringNotContainsString('having trouble', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_an_english_invitation_has_an_english_date_and_no_exclamation_marks(): void
    {
        $html = html_entity_decode($this->render($this->invitation('en')), ENT_QUOTES);

        $this->assertStringContainsString('12 Oct 2026 14:30 UTC', $html);
        $this->assertStringContainsString('All rights reserved.', $html);
        $this->assertStringContainsString('button doesn’t work, copy and paste the URL below', $html);
        $this->assertStringNotContainsString('Hello!', $html);
        $this->assertStringNotContainsString('Whoops!', $html);
    }

    public function test_the_new_device_alert_formats_its_time_in_the_users_language(): void
    {
        $at = CarbonImmutable::parse('2026-10-12 14:30:00', 'UTC');
        $notification = (new NewDeviceSignIn('Amina', 'sms', '192.0.2.1', 'Firefox', $at))->locale('fr');

        $sms = $this->withLocale('fr', fn () => $notification->toSms(new AnonymousNotifiable));

        $this->assertStringContainsString('12 oct. 2026 14:30 UTC', $sms);
        $this->assertStringContainsString('12 oct. 2026 14:30 UTC', html_entity_decode($this->render($notification), ENT_QUOTES));
    }
}
