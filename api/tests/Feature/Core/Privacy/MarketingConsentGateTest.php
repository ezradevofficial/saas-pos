<?php

namespace Tests\Feature\Core\Privacy;

use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Privacy\Consents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08 (KE DPA): every marketing send checks consent first. A marketing
 * event type reaches only customers who consented on the channel; the
 * others get a skipped delivery `no_consent` and nothing is sent. Consent
 * is checked again at hand-over, so a withdrawal after queueing stops the
 * message. Transactional (system) messages are unaffected.
 */
class MarketingConsentGateTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    private const OFFER = 'core.marketing.test_offer';

    private const RECEIPT = 'core.notification.receipt_test';

    private Party $amina;

    private Party $baraka;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();

        $types = app(EventTypes::class);
        $types->register(new EventType(
            key: self::OFFER,
            placeholders: ['sender_name' => 'Duka', 'message' => 'Offer'],
            defaultChannels: [Channels::SMS],
            channels: EventType::MARKETING_CHANNELS,
            langKey: 'notifications.events.core.notification.test',
            contactsOnly: true,
            marketing: true,
        ));
        $types->register(new EventType(
            key: self::RECEIPT,
            placeholders: ['sender_name' => 'Duka', 'message' => 'Receipt'],
            defaultChannels: [Channels::SMS],
            channels: [Channels::EMAIL, Channels::SMS],
            langKey: 'notifications.events.core.notification.test',
            system: true,
            contactsOnly: true,
        ));

        [$this->amina, $this->baraka] = $this->inTenant(fn () => [
            Party::query()->create(['company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Amina Otieno', 'roles' => ['customer']]),
            Party::query()->create(['company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Baraka Mwangi', 'roles' => ['customer']]),
        ]);
    }

    private function consent(Party $party, string $channel, bool $granted): void
    {
        $this->inTenant(fn () => app(Consents::class)->record($this->owner, 'customer', $party->id, 'marketing', $channel, $granted, 'back_office', 'Asked at the till'));
    }

    /** @param  list<array{0: Party, 1: string, 2: string}>  $to  party, channel, address */
    private function offer(array $to): Collection
    {
        return $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            self::OFFER, [], ['sender_name' => 'Duka', 'message' => '10% off this weekend'], null,
            array_map(fn (array $a) => new NotificationAddress($a[1], $a[2], $a[0]->name, 'en', $a[0]->id), $to),
        )));
    }

    public function test_a_marketing_send_skips_a_customer_without_consent(): void
    {
        $this->consent($this->amina, 'any', true);

        $deliveries = $this->offer([[$this->amina, Channels::SMS, '+254712000111'], [$this->baraka, Channels::SMS, '+254733000222']])->keyBy('recipient');

        $this->assertSame('delivered', $this->inTenant(fn () => $deliveries['+254712000111']->fresh()->status));
        $skipped = $this->inTenant(fn () => $deliveries['+254733000222']->fresh());
        $this->assertSame([NotificationDelivery::SKIPPED, NotificationDelivery::REASON_NO_CONSENT], [$skipped->status, $skipped->reason]);
        $this->assertSame($this->baraka->id, $skipped->consent_subject_id);
        $this->assertSame(['+254712000111'], array_map(fn ($m) => $m->to, $this->fakeDriver('sms')->sent));
    }

    public function test_consent_is_per_channel(): void
    {
        $this->consent($this->amina, 'sms', true);

        $deliveries = $this->offer([[$this->amina, Channels::SMS, '+254712000111'], [$this->amina, Channels::WHATSAPP, '+254712000111'], [$this->amina, Channels::EMAIL, 'amina@example.com']])->keyBy('channel');

        $this->assertSame('delivered', $this->inTenant(fn () => $deliveries['sms']->fresh()->status));
        $this->assertSame(NotificationDelivery::REASON_NO_CONSENT, $deliveries['whatsapp']->reason);
        $this->assertSame(NotificationDelivery::REASON_NO_CONSENT, $deliveries['email']->reason);
        $this->assertSame([], $this->fakeDriver('whatsapp')->sent);
        Mail::assertNothingSent();
    }

    public function test_a_withdrawal_stops_a_message_already_queued(): void
    {
        $this->consent($this->amina, 'any', true);
        Queue::fake();
        $delivery = $this->offer([[$this->amina, Channels::SMS, '+254712000111']])->sole();
        $this->assertSame(NotificationDelivery::QUEUED, $delivery->status);

        // Withdrawn on SMS before the worker picks it up.
        $this->consent($this->amina, 'sms', false);
        $this->inTenant(fn () => Queue::pushed(SendDelivery::class)->sole()->handle(app(ChannelDrivers::class), app(EventTypes::class)));

        $stored = $this->inTenant(fn () => $delivery->fresh());
        $this->assertSame([NotificationDelivery::SKIPPED, NotificationDelivery::REASON_NO_CONSENT], [$stored->status, $stored->reason]);
        $this->assertSame([], $this->fakeDriver('sms')->sent);
        $this->assertFalse($this->inTenant(fn () => app(Consents::class)->allows('customer', $this->amina->id, 'marketing', 'sms')));
        // The other channels keep the earlier grant to every channel.
        $this->assertTrue($this->inTenant(fn () => app(Consents::class)->allows('customer', $this->amina->id, 'marketing', 'email')));
    }

    public function test_transactional_messages_are_unaffected(): void
    {
        $this->consent($this->baraka, 'any', false);

        $delivery = $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            self::RECEIPT, [], ['sender_name' => 'Duka', 'message' => 'Your order is ready'], null,
            [new NotificationAddress(Channels::SMS, '+254733000222', 'Baraka', 'en')],
        ))->sole());

        $this->assertSame('delivered', $this->inTenant(fn () => $delivery->fresh()->status));
        $this->assertNull($delivery->consent_subject_id);
        $this->assertCount(1, $this->fakeDriver('sms')->sent);
    }

    public function test_a_marketing_type_reaches_named_customers_only(): void
    {
        try {
            $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(self::OFFER, [$this->owner], ['message' => 'x'])));
            $this->fail('A marketing type sent to a user.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('customer contacts only', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            self::OFFER, [], ['message' => 'x'], null, [new NotificationAddress(Channels::SMS, '+254712000111', 'Amina', 'en')],
        )));
    }

    public function test_a_marketing_type_cannot_be_a_system_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventType(key: 'core.marketing.bad_offer', channels: EventType::MARKETING_CHANNELS, defaultChannels: [Channels::SMS], system: true, marketing: true);
    }

    public function test_a_campaign_audience_keeps_only_consenting_customers(): void
    {
        $this->consent($this->amina, 'any', true);
        $this->consent($this->baraka, 'email', true);
        $this->consent($this->baraka, 'any', false);

        $this->inTenant(function () {
            $consents = app(Consents::class);
            $this->assertSame([$this->amina->id], $consents->allowedAmong('customer', [$this->amina->id, $this->baraka->id, 'not-a-uuid'], 'marketing', 'email'));
            $this->assertSame([], $consents->allowedAmong('customer', [], 'marketing', 'sms'));
        });
    }
}
