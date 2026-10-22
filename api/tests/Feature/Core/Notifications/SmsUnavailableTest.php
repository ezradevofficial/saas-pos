<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NOT-01 in email-only mode (no SMS provider, SmsAvailability): SMS
 * deliveries are skipped with the reason `sms_unavailable`, never counted
 * as sent, and the message falls back to WhatsApp where configured, else to
 * email. A contact's text (an order update) goes by WhatsApp where
 * configured; its email goes as usual.
 */
class SmsUnavailableTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    /** A transactional system type to contacts, like the storefront's order updates. */
    private const ORDER = 'core.notification.order_test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
        app(EventTypes::class)->register(new EventType(
            key: self::ORDER,
            placeholders: ['sender_name' => 'Amina', 'message' => 'Hi'],
            defaultChannels: [Channels::SMS, Channels::EMAIL],
            channels: [Channels::SMS, Channels::EMAIL, Channels::WHATSAPP],
            langKey: 'notifications.events.core.notification.test',
            system: true,
            contactsOnly: true,
        ));
        // Email-only mode: no SMS provider.
        config(['services.sms.driver' => 'none']);
    }

    /** The owner, reachable by phone, takes the test event by SMS only. */
    private function smsOnly(): void
    {
        $this->inTenant(function () {
            $this->owner->forceFill(['phone' => '+254722000111', 'phone_verified_at' => now()])->save();
            NotificationPreference::create([
                'user_id' => $this->owner->id, 'event_type' => 'core.notification.test',
                'channels' => ['in_app' => false, 'email' => false, 'sms' => true],
            ]);
        });
    }

    /** @return Collection<string, NotificationDelivery> the owner's deliveries by channel */
    private function ownersDeliveries(): Collection
    {
        return $this->inTenant(fn () => NotificationDelivery::where('user_id', $this->owner->id)->get()->keyBy('channel'));
    }

    private function sendOrder(array $addresses): Collection
    {
        return $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            self::ORDER, [], ['message' => 'Order WEB-0012 is ready', 'sender_name' => 'Duka'], null, $addresses,
        )));
    }

    public function test_an_sms_is_skipped_as_sms_unavailable_and_goes_by_whatsapp_where_configured(): void
    {
        $this->smsOnly();

        $this->sendTest([$this->owner]);

        $deliveries = $this->ownersDeliveries();
        $this->assertSame(['skipped', 'sms_unavailable'], [$deliveries['sms']->status, $deliveries['sms']->reason]);
        $this->assertNull($deliveries['sms']->sent_at);
        $this->assertSame(['delivered', '+254722000111'], [$deliveries['whatsapp']->status, $deliveries['whatsapp']->recipient]);
        $this->assertArrayNotHasKey('email', $deliveries->all());
        $this->assertCount(1, $this->fakeDriver('whatsapp')->sent);
        Mail::assertNothingSent();
    }

    public function test_without_whatsapp_an_sms_falls_back_to_email(): void
    {
        config(['notifications.drivers.whatsapp' => 'none']);
        $this->smsOnly();

        $this->sendTest([$this->owner]);

        $deliveries = $this->ownersDeliveries();
        $this->assertSame(['skipped', 'sms_unavailable'], [$deliveries['sms']->status, $deliveries['sms']->reason]);
        $this->assertSame(['sent', $this->owner->email], [$deliveries['email']->status, $deliveries['email']->recipient]);
        $this->assertArrayNotHasKey('whatsapp', $deliveries->all());
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($this->owner->email));
    }

    public function test_a_message_that_already_goes_by_email_gets_no_second_channel(): void
    {
        $this->inTenant(function () {
            $this->owner->forceFill(['phone' => '+254722000111', 'phone_verified_at' => now()])->save();
            NotificationPreference::create([
                'user_id' => $this->owner->id, 'event_type' => 'core.notification.test', 'channels' => ['sms' => true],
            ]);
        });

        $this->sendTest([$this->owner]);

        $deliveries = $this->ownersDeliveries();
        $this->assertSame(['email', 'in_app', 'sms'], $deliveries->keys()->sort()->values()->all());
        $this->assertSame('sms_unavailable', $deliveries['sms']->reason);
        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_a_contacts_text_goes_by_whatsapp_and_its_email_as_usual(): void
    {
        $deliveries = $this->sendOrder([
            new NotificationAddress(Channels::SMS, '+254711000222', 'Wanjiru', 'en'),
            new NotificationAddress(Channels::EMAIL, 'wanjiru@example.com', 'Wanjiru', 'en'),
        ])->keyBy('channel');

        $this->inTenant(function () use ($deliveries) {
            $this->assertSame(['skipped', 'sms_unavailable'], [$deliveries['sms']->fresh()->status, $deliveries['sms']->reason]);
            $this->assertSame(['delivered', '+254711000222'], [$deliveries['whatsapp']->fresh()->status, $deliveries['whatsapp']->recipient]);
            $this->assertSame('sent', $deliveries['email']->fresh()->status);
        });
        $this->assertCount(1, $this->fakeDriver('whatsapp')->sent);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('wanjiru@example.com'));
    }

    public function test_without_whatsapp_a_contacts_text_is_only_skipped(): void
    {
        config(['notifications.drivers.whatsapp' => 'none']);

        $deliveries = $this->sendOrder([new NotificationAddress(Channels::SMS, '+254711000222', 'Wanjiru', 'en')]);

        $this->assertSame([['sms', 'skipped', 'sms_unavailable']], $deliveries->map(fn ($d) => [$d->channel, $d->status, $d->reason])->all());
    }

    public function test_an_sms_queued_before_sms_was_switched_off_is_skipped_at_hand_over(): void
    {
        config(['services.sms.driver' => null]);
        $this->smsOnly();
        Bus::fake([SendDelivery::class]);
        $this->sendTest([$this->owner]);
        $delivery = $this->inTenant(fn () => NotificationDelivery::where('channel', 'sms')->sole());
        $this->assertSame('queued', $delivery->status);
        $sms = $this->fakeDriver('sms');

        config(['services.sms.driver' => 'none']);
        $job = new SendDelivery($this->owner->tenant_id, $delivery->id);
        (new TenantAware)->handle($job, fn (SendDelivery $job) => app()->call([$job, 'handle']));

        $this->inTenant(fn () => $this->assertSame(['skipped', 'sms_unavailable'], [$delivery->fresh()->status, $delivery->fresh()->reason]));
        $this->assertCount(0, $sms->sent);
    }

    /** NOT-01: the event types report SMS as unavailable, so the preferences screen hides it. */
    public function test_the_event_types_report_sms_as_unavailable(): void
    {
        $sms = fn () => collect(collect($this->getJson('/api/v1/notification-event-types', $this->headersFor())->assertOk()->json('data'))
            ->firstWhere('event_type', 'core.notification.test')['channels'])->firstWhere('channel', 'sms')['available'];

        $this->assertFalse($sms());

        config(['services.sms.driver' => 'log']);
        $this->assertTrue($sms());
    }

    public function test_the_skip_reason_reads_in_english_and_french(): void
    {
        $this->assertNotSame('notifications.reasons.sms_unavailable', __('notifications.reasons.sms_unavailable', [], 'en'));
        $this->assertNotSame(__('notifications.reasons.sms_unavailable', [], 'en'), __('notifications.reasons.sms_unavailable', [], 'fr'));
    }
}
