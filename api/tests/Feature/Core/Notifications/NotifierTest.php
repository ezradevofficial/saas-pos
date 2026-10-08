<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\Models\NotificationSetting;
use App\Core\Notifications\Models\NotificationTemplate;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Notifications\UnknownEventType;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantContextMissing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-01 channels, NOT-02 one service, NOT-03 templates in the
// recipient's language, NOT-04 preferences and mandatory channels,
// NOT-06 a delivery per channel with skip reasons.
class NotifierTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
    }

    public function test_the_default_channels_write_the_inbox_and_send_the_email_in_the_recipients_language(): void
    {
        $colleague = $this->reachableColleague(['locale' => 'fr']);

        $deliveries = $this->sendTest([$this->owner, $colleague], ['message' => 'Inventaire à 17 h.']);

        // in_app and email (the defaults) for each recipient.
        $this->assertCount(4, $deliveries);
        $this->inTenant(function () use ($colleague) {
            $mine = InAppNotification::where('user_id', $this->owner->id)->sole();
            $this->assertSame('Test message from Amina', $mine->subject);
            $this->assertStringContainsString("Hello Owner,\n\nAmina sent you a test message:", $mine->body);
            $this->assertSame('/notifications', $mine->link);

            $theirs = InAppNotification::where('user_id', $colleague->id)->sole();
            $this->assertSame('Message de test de Amina', $theirs->subject);
            $this->assertStringContainsString('Inventaire à 17 h.', $theirs->body);

            $this->assertSame(
                ['delivered', 'sent'],
                NotificationDelivery::where('user_id', $colleague->id)->orderBy('channel')->pluck('status')->sort()->values()->all(),
            );
            $email = NotificationDelivery::where('user_id', $colleague->id)->where('channel', 'email')->sole();
            $this->assertSame($colleague->email, $email->recipient);
            $this->assertSame('fr', $email->locale);
            $this->assertSame(1, $email->attempts);
            $this->assertNotNull($email->sent_at);
        });

        Mail::assertSent(NotificationMail::class, 2);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($colleague->email)
            && $mail->mailSubject === 'Message de test de Amina' && $mail->locale === 'fr');
    }

    public function test_html_email_escapes_values_while_plain_text_keeps_them(): void
    {
        $this->sendTest([$this->owner], ['message' => '<script>alert(1)</script> & <b>bold</b>']);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            $html = $mail->render();
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;', $html);
            $this->assertStringContainsString('<br>', $html, 'line breaks are kept');
            $this->assertStringContainsString('<script>alert(1)</script> & <b>bold</b>', $mail->text);
            $this->assertStringContainsString(rtrim((string) config('app.frontend_url'), '/').'/notifications', $html);

            return true;
        });
    }

    public function test_channels_without_a_contact_or_a_driver_are_skipped_with_a_reason(): void
    {
        config(['notifications.drivers.whatsapp' => 'none']);
        $noEmail = $this->inTenant(fn () => $this->colleague($this->owner, ['email' => null, 'email_verified_at' => null, 'phone' => '+254711000001', 'phone_verified_at' => null]));

        $this->inTenant(function () use ($noEmail) {
            foreach ([$this->owner, $noEmail] as $user) {
                NotificationPreference::create([
                    'user_id' => $user->id, 'event_type' => 'core.notification.test',
                    'channels' => ['sms' => true, 'whatsapp' => true, 'push' => true],
                ]);
            }
        });

        $this->sendTest([$this->owner, $noEmail]);

        $this->inTenant(function () use ($noEmail) {
            $byChannel = fn ($user) => NotificationDelivery::where('user_id', $user->id)->get()->keyBy('channel');

            $owner = $byChannel($this->owner);
            $this->assertSame(['email', 'in_app', 'push', 'sms', 'whatsapp'], $owner->keys()->sort()->values()->all());
            $this->assertSame('sent', $owner['email']->status);
            $this->assertSame('delivered', $owner['push']->status, 'the fake push driver reports delivery');
            $this->assertSame($this->owner->id, $owner['push']->recipient);
            $this->assertSame(['skipped', 'no_phone'], [$owner['sms']->status, $owner['sms']->reason]);
            $this->assertSame(['skipped', 'channel_unavailable'], [$owner['whatsapp']->status, $owner['whatsapp']->reason]);

            $other = $byChannel($noEmail);
            $this->assertSame(['skipped', 'no_email'], [$other['email']->status, $other['email']->reason]);
            $this->assertSame(['skipped', 'no_phone'], [$other['sms']->status, $other['sms']->reason], 'an unverified phone is not used');
        });

        $this->assertCount(2, $this->fakeDriver('push')->sent);
        $this->assertCount(0, $this->fakeDriver('sms')->sent);
    }

    public function test_sms_uses_the_short_text_and_a_verified_phone(): void
    {
        $colleague = $this->reachableColleague();
        $this->inTenant(fn () => NotificationPreference::create([
            'user_id' => $colleague->id, 'event_type' => 'core.notification.test', 'channels' => ['sms' => true, 'email' => false],
        ]));

        $this->sendTest([$colleague]);

        $sent = $this->fakeDriver('sms')->sent;
        $this->assertCount(1, $sent);
        $this->assertSame($colleague->phone, $sent[0]->to);
        $this->assertSame(config('app.name').': test message from Amina: Stock count at 5 pm.', $sent[0]->body);
        Mail::assertNothingSent();
    }

    public function test_preferences_switch_channels_and_mandatory_channels_win(): void
    {
        $this->inTenant(fn () => NotificationPreference::create([
            'user_id' => $this->owner->id, 'event_type' => 'core.notification.test', 'channels' => ['email' => false, 'in_app' => false],
        ]));

        $this->assertCount(0, $this->sendTest([$this->owner]));

        // The tenant makes email mandatory: the user's "off" no longer applies to it.
        $this->inTenant(fn () => NotificationSetting::create(['event_type' => 'core.notification.test', 'mandatory_channels' => ['email']]));
        $deliveries = $this->sendTest([$this->owner]);

        $this->assertSame(['email'], $deliveries->pluck('channel')->all());
        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_tenant_templates_replace_the_defaults_per_channel_then_for_all_channels(): void
    {
        $this->inTenant(function () {
            NotificationTemplate::create(['event_type' => 'core.notification.test', 'channel' => 'all', 'subject' => 'Note from {sender_name}', 'body' => 'All: {message}']);
            NotificationTemplate::create(['event_type' => 'core.notification.test', 'channel' => 'email', 'subject' => null, 'body' => 'Email for {recipient_name}: {message}']);
        });

        $this->sendTest([$this->owner]);

        $this->inTenant(function () {
            $inApp = InAppNotification::sole();
            $this->assertSame(['Note from Amina', 'All: Stock count at 5 pm.'], [$inApp->subject, $inApp->body]);

            $email = NotificationDelivery::where('channel', 'email')->sole();
            // A blank subject on the channel's own text falls back to the default subject.
            $this->assertSame(['Test message from Amina', 'Email for Owner: Stock count at 5 pm.'], [$email->subject, $email->body]);
        });
    }

    public function test_a_tenant_text_is_written_once_and_reaches_every_language_as_is(): void
    {
        // NOT-03 (owner decision 2026-10-08): one text, in the organisation's
        // language; an English and a French reader get the same words, with
        // their own values; without a text each gets the default in theirs.
        $french = $this->reachableColleague(['locale' => 'fr', 'name' => 'Owner']);
        $this->inTenant(fn () => NotificationTemplate::create([
            'event_type' => 'core.notification.test', 'channel' => 'in_app', 'subject' => 'Ujumbe kutoka {sender_name}', 'body' => 'Habari {recipient_name}: {message}',
        ]));

        $this->sendTest([$this->owner, $french]);

        $this->inTenant(function () use ($french) {
            $mine = InAppNotification::where('user_id', $this->owner->id)->sole();
            $theirs = InAppNotification::where('user_id', $french->id)->sole();
            $this->assertSame(['Ujumbe kutoka Amina', 'Habari Owner: Stock count at 5 pm.'], [$mine->subject, $mine->body]);
            $this->assertSame([$mine->subject, $mine->body], [$theirs->subject, $theirs->body]);

            // Email has no text of its own and no text for all channels: the
            // default, in each recipient's language.
            $this->assertSame('Test message from Amina', NotificationDelivery::where('user_id', $this->owner->id)->where('channel', 'email')->value('subject'));
            $this->assertSame('Message de test de Amina', NotificationDelivery::where('user_id', $french->id)->where('channel', 'email')->value('subject'));
        });
    }

    public function test_only_active_users_of_the_current_tenant_receive_it(): void
    {
        $other = $this->otherTenant();
        $former = $this->inTenant(fn () => $this->colleague($this->owner, ['status' => 'deactivated']));

        $deliveries = $this->sendTest([$this->owner, $other['user']->id, $former, 'not-a-uuid']);

        $this->assertSame([$this->owner->id], $deliveries->pluck('user_id')->unique()->values()->all());
        $this->asTenant($other['user']->tenant_id, fn () => $this->assertSame(0, NotificationDelivery::count() + InAppNotification::count()));
    }

    public function test_users_reach_the_inbox_only_through_our_model_and_keep_notify(): void
    {
        $this->sendTest([$this->owner]);

        $this->inTenant(function () {
            $relation = $this->owner->notifications();
            $this->assertInstanceOf(InAppNotification::class, $relation->getRelated());
            $this->assertSame(1, $relation->count());
        });
        // Laravel's database-notification shape is gone; notify() (sign-in codes) stays.
        $this->assertFalse(method_exists($this->owner, 'readNotifications'));
        $this->assertFalse(method_exists($this->owner, 'unreadNotifications'));
        $this->assertTrue(method_exists($this->owner, 'notify'));
    }

    public function test_only_relative_app_paths_are_kept_as_links(): void
    {
        Log::spy();

        foreach (['https://evil.example/login', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', 'approvals/1', '/a b'] as $link) {
            $this->sendTest([$this->owner], link: $link);
        }
        $this->sendTest([$this->owner], link: '/approvals/42?tab=history');

        $this->inTenant(fn () => $this->assertSame(['/approvals/42?tab=history'], InAppNotification::whereNotNull('link')->pluck('link')->all()));
        Log::shouldHaveReceived('warning')->with('Notification link dropped: only relative app paths are allowed', \Mockery::any())->times(6);
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            $html = $mail->render();
            $this->assertStringNotContainsString('evil.example', $html);
            $this->assertStringNotContainsString('javascript:', $html);

            return true;
        });
        $this->assertNull(NotificationMail::absolute('https://evil.example'));
        $this->assertNull(NotificationMail::absolute('//evil.example'));
    }

    public function test_an_unknown_event_type_or_no_tenant_is_refused(): void
    {
        $this->expectException(UnknownEventType::class);
        $this->sendTest([$this->owner], type: 'core.nothing.here');
    }

    public function test_sending_needs_a_tenant_context(): void
    {
        app(TenantContext::class)->set(null);

        $this->expectException(TenantContextMissing::class);
        app(Notifier::class)->send(new NotificationEvent('core.notification.test', [$this->owner]));
    }

    public function test_event_types_are_validated_when_registered(): void
    {
        $types = app(EventTypes::class);

        foreach ([
            fn () => new EventType('Bad Key'),
            fn () => new EventType('core.x.y', ['Bad-Name' => 'x']),
            fn () => new EventType('core.x.y', defaultChannels: ['fax']),
            fn () => new EventType('core.x.y', defaultChannels: [Channels::SMS], channels: [Channels::IN_APP]),
            fn () => $types->register(new EventType('core.x.y', ['recipient_name' => 'x'])),
        ] as $make) {
            try {
                $make();
                $this->fail('An invalid event type was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
