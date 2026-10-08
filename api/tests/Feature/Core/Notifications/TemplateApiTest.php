<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\Models\NotificationTemplate;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-03: admins edit the text per event, channel and language with the
// event's placeholders (core.notification_template.view|edit); unknown
// placeholders refused; preview with sample values; reset to default.
class TemplateApiTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    private const EVENT = 'core.notification.test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
    }

    private function slot(array $data, string $channel, string $locale): array
    {
        return collect($data['templates'])->first(fn ($t) => $t['channel'] === $channel && $t['locale'] === $locale);
    }

    public function test_templates_show_the_text_in_use_per_channel_and_language(): void
    {
        $list = $this->getJson('/api/v1/notification-templates', $this->headersFor())->assertOk()->json('data');
        $test = collect($list)->firstWhere('event_type', self::EVENT);
        $this->assertSame('Test message', $test['label']);
        // all + 5 channels, in 2 languages.
        $this->assertCount(12, $test['templates']);
        $this->assertSame(['sender_name', 'message', 'recipient_name', 'app_name'], array_column($test['placeholders'], 'name'));

        $email = $this->slot($test, 'email', 'fr');
        $this->assertSame(['Message de test de {sender_name}', 'default', false], [$email['subject'], $email['source'], $email['overridden']]);
        $this->assertSame('{app_name}: test message from {sender_name}: {message}', $this->slot($test, 'sms', 'en')['body']);

        $this->getJson('/api/v1/notification-templates/'.self::EVENT, $this->headersFor())->assertOk()->assertJsonPath('data.event_type', self::EVENT);
        $this->getJson('/api/v1/notification-templates/core.nothing.here', $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/notification-templates/not-an-event', $this->headersFor())->assertNotFound();
    }

    public function test_an_admin_edits_a_text_which_is_then_sent_and_resets_it(): void
    {
        $data = $this->putJson('/api/v1/notification-templates', [
            'event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en',
            'subject' => '{sender_name} wrote', 'body' => 'Dear {recipient_name}: {message}',
        ], $this->headersFor())->assertOk()->json('data');

        $all = $this->slot($data, 'all', 'en');
        $this->assertSame(['{sender_name} wrote', 'Dear {recipient_name}: {message}', 'all', true], [$all['subject'], $all['body'], $all['source'], $all['overridden']]);
        // Channels without their own text use it; French keeps its default.
        $this->assertSame(['all', false], [$this->slot($data, 'in_app', 'en')['source'], $this->slot($data, 'in_app', 'en')['overridden']]);
        $this->assertSame('default', $this->slot($data, 'in_app', 'fr')['source']);

        $this->sendTest([$this->owner], ['message' => 'Hello']);
        $this->inTenant(fn () => $this->assertSame(['Amina wrote', 'Dear Owner: Hello'], [InAppNotification::sole()->subject, InAppNotification::sole()->body]));

        // Changed again, then reset.
        $this->putJson('/api/v1/notification-templates', ['event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en', 'subject' => '', 'body' => 'Short: {message}'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.templates.0.subject', 'Test message from {sender_name}');
        $data = $this->postJson('/api/v1/notification-templates/reset', ['event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en'], $this->headersFor())
            ->assertOk()->json('data');
        $this->assertSame(['default', false], [$this->slot($data, 'all', 'en')['source'], $this->slot($data, 'all', 'en')['overridden']]);
        // Resetting what is already the default changes nothing.
        $this->postJson('/api/v1/notification-templates/reset', ['event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en'], $this->headersFor())->assertOk();

        $this->inTenant(function () {
            $this->assertSame(0, NotificationTemplate::count());
            $this->assertSame(
                ['core.notification_template.create', 'core.notification_template.update', 'core.notification_template.reset'],
                AuditEntry::where('action', 'like', 'core.notification_template.%')->orderBy('seq')->pluck('action')->all(),
            );
        });
    }

    public function test_unknown_placeholders_and_bad_slots_are_refused(): void
    {
        $put = fn (array $body) => $this->putJson('/api/v1/notification-templates', $body + ['event_type' => self::EVENT, 'channel' => 'email', 'locale' => 'en', 'body' => 'Fine {message}'], $this->headersFor());

        $put(['body' => 'Total {amount} for {message}'])->assertUnprocessable()->assertJsonValidationErrors('body')
            ->assertJsonPath('errors.body.0', 'This text uses placeholders this notification doesn’t have: {amount}. Use only: {sender_name}, {message}, {recipient_name}, {app_name}.');
        $put(['subject' => 'From {sender}'])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $put(['locale' => 'sw'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $put(['channel' => 'fax'])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $put(['event_type' => 'core.nothing.here'])->assertUnprocessable()->assertJsonValidationErrors('event_type');
        $put(['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
        $put(['body' => str_repeat('x', 5001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        // Braces that are not placeholders are plain text.
        $put(['body' => 'Use {message} or {Not A Placeholder}'])->assertOk();

        // A channel the event does not go out on.
        $this->registerTestEventTypes();
        $this->putJson('/api/v1/notification-templates', ['event_type' => 'core.report.ready', 'channel' => 'sms', 'locale' => 'en', 'body' => 'x'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->getJson('/api/v1/notification-templates/core.report.ready', $this->headersFor())->assertOk()->assertJsonCount(6, 'data.templates');
    }

    public function test_sms_texts_are_short_and_subjects_are_one_line(): void
    {
        $put = fn (array $body) => $this->putJson('/api/v1/notification-templates', $body + ['event_type' => self::EVENT, 'locale' => 'en'], $this->headersFor());

        $put(['channel' => 'sms', 'body' => str_repeat('x', 481)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $put(['channel' => 'whatsapp', 'body' => str_repeat('x', 481)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $put(['channel' => 'sms', 'body' => str_repeat('x', 480)])->assertOk();
        $put(['channel' => 'email', 'body' => 'x', 'subject' => "Line one\r\nBcc: someone@example.com"])->assertUnprocessable()
            ->assertJsonPath('errors.subject.0', 'A subject is one line. Remove the line breaks.');
        $put(['channel' => 'email', 'body' => 'x', 'subject' => "Line one\nLine two"])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->postJson('/api/v1/notification-templates/preview', ['event_type' => self::EVENT, 'channel' => 'sms', 'locale' => 'en', 'body' => str_repeat('x', 481)], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_a_long_text_for_all_channels_is_cut_on_sms_and_values_never_break_the_subject(): void
    {
        $this->putJson('/api/v1/notification-templates', [
            'event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en', 'subject' => 'From {sender_name}', 'body' => str_repeat('é', 600).' {message}',
        ], $this->headersFor())->assertOk();
        $this->inTenant(function () {
            $this->owner->forceFill(['phone' => '+254722000999', 'phone_verified_at' => now()])->save();
            NotificationPreference::create(['user_id' => $this->owner->id, 'event_type' => self::EVENT, 'channels' => ['sms' => true]]);
        });

        $this->sendTest([$this->owner], ['sender_name' => "Amina\r\nBcc: x@example.com"]);

        $sms = $this->fakeDriver('sms')->sent[0]->body;
        $this->assertSame(480, mb_strlen($sms));
        $this->assertStringEndsWith('é…', $sms);
        $this->inTenant(function () {
            $this->assertSame(600 + 1 + strlen('Stock count at 5 pm.'), mb_strlen(InAppNotification::sole()->body), 'other channels keep the full text');
            $this->assertSame('From Amina Bcc: x@example.com', InAppNotification::sole()->subject);
        });
    }

    public function test_preview_renders_sample_values_escaped_for_html(): void
    {
        $preview = $this->postJson('/api/v1/notification-templates/preview', [
            'event_type' => self::EVENT, 'channel' => 'email', 'locale' => 'fr',
            'body' => "<b>{sender_name}</b>\n{message}",
        ], $this->headersFor())->assertOk()->json('data');

        $this->assertSame('Message de test de Amina Otieno', $preview['subject']);
        $this->assertSame("<b>Amina Otieno</b>\nThe shop opens at 08:00 tomorrow.", $preview['body']);
        $this->assertSame("&lt;b&gt;Amina Otieno&lt;/b&gt;<br>\nThe shop opens at 08:00 tomorrow.", $preview['html']);

        // Without a text: the one in use; common placeholders get samples too.
        $preview = $this->postJson('/api/v1/notification-templates/preview', ['event_type' => self::EVENT, 'channel' => 'sms', 'locale' => 'en'], $this->headersFor())->assertOk();
        $preview->assertJsonPath('data.body', config('app.name').': test message from Amina Otieno: The shop opens at 08:00 tomorrow.');

        $this->postJson('/api/v1/notification-templates/preview', ['event_type' => self::EVENT, 'channel' => 'email', 'locale' => 'en', 'body' => '{nope}'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->inTenant(fn () => $this->assertSame(0, NotificationTemplate::count()));
    }

    public function test_templates_need_their_permissions(): void
    {
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $auditor = $this->headersFor($this->userWith('read_only_auditor', Scope::tenant()));
        $body = ['event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en', 'body' => 'x'];

        $this->getJson('/api/v1/notification-templates', $manager)->assertForbidden();
        $this->getJson('/api/v1/notification-templates/'.self::EVENT, $manager)->assertForbidden();
        $this->postJson('/api/v1/notification-templates/preview', $body, $manager)->assertForbidden();
        $this->putJson('/api/v1/notification-templates', $body, $manager)->assertForbidden();

        // The auditor reads (core.notification_template.view) but does not edit.
        $this->getJson('/api/v1/notification-templates', $auditor)->assertOk();
        $this->postJson('/api/v1/notification-templates/preview', $body, $auditor)->assertOk();
        $this->putJson('/api/v1/notification-templates', $body, $auditor)->assertForbidden();
        $this->postJson('/api/v1/notification-templates/reset', $body, $auditor)->assertForbidden();

        // A company-wide admin role is not enough: templates are tenant-wide.
        $companyAdmin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $this->putJson('/api/v1/notification-templates', $body, $companyAdmin)->assertForbidden();
        $this->inTenant(fn () => $this->assertSame(0, NotificationTemplate::count()));
    }

    public function test_another_tenants_texts_are_its_own(): void
    {
        $this->putJson('/api/v1/notification-templates', ['event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en', 'body' => 'Acme only {message}'], $this->headersFor())->assertOk();
        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));

        $theirs = $this->getJson('/api/v1/notification-templates/'.self::EVENT, $headers)->assertOk()->json('data');
        $this->assertSame('default', $this->slot($theirs, 'all', 'en')['source']);
        $this->assertStringNotContainsString('Acme only', json_encode($theirs));
        $this->postJson('/api/v1/notification-templates/reset', ['event_type' => self::EVENT, 'channel' => 'all', 'locale' => 'en'], $headers)->assertOk();
        $this->inTenant(fn () => $this->assertSame(1, NotificationTemplate::count()));
    }
}
