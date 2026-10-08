<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\IdentityNotices;
use App\Core\Identity\Models\Invitation;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// L10N-01: invitations and sign-in alerts (sent through the Notifier, ADR
// 009) use the recipient's language, dates included.
class NotificationLocaleTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 14:30:00', 'UTC'));
        $this->setUpOrganisation();
    }

    private function invite(): void
    {
        $this->postJson('/api/v1/invitations', [
            'name' => 'Amina',
            'email' => 'amina@example.com',
            'assignments' => [['role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationA->id]],
        ], $this->headersFor())->assertCreated();
    }

    private function sentMail(): NotificationMail
    {
        $sent = Mail::sent(NotificationMail::class);
        $this->assertCount(1, $sent);

        return $sent->first();
    }

    public function test_a_french_invitation_has_french_text_date_and_footer(): void
    {
        $this->inTenant(fn () => Tenant::findOrFail($this->owner->tenant_id)->forceFill(['default_locale' => 'fr'])->save());
        $this->invite();

        $mail = $this->sentMail();
        $this->assertStringContainsString('12 oct. 2026 14:30 UTC', $mail->text);
        $html = html_entity_decode($mail->render(), ENT_QUOTES);
        $this->assertStringContainsString('vous invite à rejoindre', $html);
        $this->assertStringContainsString(__('notifications.mail.open', [], 'fr'), $html);
        $this->assertStringNotContainsString('has invited you', $html);
    }

    public function test_an_english_invitation_has_an_english_date_and_no_exclamation_marks(): void
    {
        $this->invite();

        $mail = $this->sentMail();
        $this->assertStringContainsString('12 Oct 2026 14:30 UTC', $mail->text);
        $this->assertStringNotContainsString('!', $mail->text.$mail->mailSubject);
        $this->assertSame(Invitation::VALID_DAYS, 7);
    }

    public function test_the_new_device_alert_formats_its_time_in_the_users_language(): void
    {
        $user = $this->inTenant(fn () => $this->colleague($this->owner, ['locale' => 'fr']));
        $this->signIn($user->email, null, ['User-Agent' => 'Device A'])->assertOk();
        $this->signIn($user->email, null, ['User-Agent' => 'Device B'])->assertOk();

        $alert = $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', IdentityNotices::NEW_DEVICE)->where('user_id', $user->id)->sole());
        $this->assertSame('fr', $alert->locale);
        $this->assertStringContainsString('le 5 oct. 2026 14:30 UTC', $alert->body);
        $this->assertStringStartsWith('Nouvelle connexion', (string) $alert->subject);
    }
}
