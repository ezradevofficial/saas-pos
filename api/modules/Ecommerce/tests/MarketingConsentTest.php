<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Privacy\Consents;
use App\Core\Privacy\Models\ConsentRecord;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Ecommerce\Customers\CustomerCodeNotification;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08 (KE DPA), ECOM-08: storefront sign-up records marketing consent
 * through the core consent service (source `storefront`, the wording
 * version shown, the evidence), unticked by default; the account page
 * withdraws it; a marketing send skips a customer who has not consented,
 * while order messages (transactional) still go out.
 */
class MarketingConsentTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    private const OFFER = 'ecommerce.marketing.test_offer';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
        app(EventTypes::class)->register(new EventType(
            key: self::OFFER,
            placeholders: ['sender_name' => 'Duka', 'message' => 'Offer'],
            defaultChannels: [Channels::SMS],
            channels: EventType::MARKETING_CHANNELS,
            langKey: 'notifications.events.core.notification.test',
            contactsOnly: true,
            marketing: true,
        ));
    }

    /** @return array{0: string, 1: array<string, mixed>} the challenge id and the verify answer */
    private function signUp(string $contact, array $extra = []): array
    {
        $challenge = $this->shopCall('POST', 'duka/auth/code', ['contact' => $contact])->assertCreated()->json('data.challenge_id');
        $code = null;
        foreach (Notification::sent(new AnonymousNotifiable, CustomerCodeNotification::class) as $notification) {
            $code = $notification->code;
        }

        return [$challenge, $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $challenge, 'code' => $code, ...$extra])->assertOk()->json('data')];
    }

    private function offerTo(string $phone): NotificationDelivery
    {
        return $this->inTenant(function () use ($phone) {
            $customer = Customer::query()->where('phone', $phone)->sole();

            return app(Notifier::class)->send(new NotificationEvent(
                self::OFFER, [], ['sender_name' => 'Duka', 'message' => 'Weekend offer'], null,
                [new NotificationAddress(Channels::SMS, $phone, $customer->name, 'en', $customer->party_id ?? '00000000-0000-7000-8000-000000000000')],
            ))->sole()->fresh();
        });
    }

    public function test_sign_up_with_consent_records_it_with_the_wording_and_evidence(): void
    {
        [$challenge, $data] = $this->signUp('0712345678', ['marketing_consent' => true, 'wording_version' => '2026_10']);
        $this->assertTrue($data['customer']['marketing_consent']);

        $this->inTenant(function () use ($challenge) {
            $customer = Customer::query()->where('phone', '+254712345678')->sole();
            $this->assertNotNull($customer->party_id, 'the consent belongs to the customer\'s party, created at sign-up');
            $record = ConsentRecord::query()->sole();
            $this->assertSame(['customer', $customer->party_id, 'marketing', 'any', true, 'storefront', '2026_10', null],
                [$record->subject_type, $record->subject_id, $record->purpose, $record->channel, $record->granted, $record->source, $record->wording_version, $record->recorded_by]);
            $this->assertStringContainsString("code {$challenge}", (string) $record->evidence);
            $this->assertStringContainsString('storefront duka', (string) $record->evidence);
            $this->assertStringNotContainsString('712345678', (string) $record->evidence);
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.consent.grant')->count());
        });

        $this->assertSame('delivered', $this->offerTo('+254712345678')->status);

        // Signing in again with the box ticked records nothing new.
        $this->travel(2)->minutes();
        $this->signUp('0712345678', ['marketing_consent' => true, 'wording_version' => '2026_10']);
        $this->assertSame(1, $this->inTenant(fn () => ConsentRecord::query()->count()));
    }

    public function test_sign_up_without_consent_records_nothing_and_marketing_skips_the_customer(): void
    {
        [, $data] = $this->signUp('0712345678');
        $this->assertFalse($data['customer']['marketing_consent']);
        $this->assertSame(0, $this->inTenant(fn () => ConsentRecord::query()->count()));

        $delivery = $this->offerTo('+254712345678');
        $this->assertSame([NotificationDelivery::SKIPPED, NotificationDelivery::REASON_NO_CONSENT], [$delivery->status, $delivery->reason]);
        $this->assertSame([], $this->fakeSms());
    }

    public function test_consent_needs_a_known_wording_version(): void
    {
        $challenge = $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712345678'])->assertCreated()->json('data.challenge_id');
        $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $challenge, 'code' => '123456', 'marketing_consent' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('wording_version');
        $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $challenge, 'code' => '123456', 'marketing_consent' => true, 'wording_version' => '1999_01'])
            ->assertUnprocessable()->assertJsonValidationErrors('wording_version');
    }

    public function test_withdrawing_in_the_account_stops_marketing_but_not_order_messages(): void
    {
        [, $data] = $this->signUp('0712345678', ['marketing_consent' => true, 'wording_version' => '2026_10']);
        $auth = ['Authorization' => "Bearer {$data['token']}"];
        $this->assertTrue($this->shopCall('GET', 'duka/account', [], $auth)->assertOk()->json('data.customer.marketing_consent'));

        $this->shopCall('PUT', 'duka/account/marketing-consent', ['granted' => false], $auth)->assertOk()->assertJsonPath('data.marketing_consent', false);
        $this->assertFalse($this->shopCall('GET', 'duka/account', [], $auth)->json('data.customer.marketing_consent'));

        $this->inTenant(function () {
            $latest = ConsentRecord::query()->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $this->assertSame([false, 'storefront', 'any'], [$latest->granted, $latest->source, $latest->channel]);
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.consent.withdraw')->count());
            $party = Customer::query()->sole()->party_id;
            foreach (EventType::MARKETING_CHANNELS as $channel) {
                $this->assertFalse(app(Consents::class)->allows('customer', $party, 'marketing', $channel));
            }
        });
        $this->assertSame(NotificationDelivery::REASON_NO_CONSENT, $this->offerTo('+254712345678')->reason);

        // An order message is transactional: it still reaches the customer.
        $this->shopCall('POST', 'duka/orders', $this->checkout($this->cart([[$this->widgetProduct, '1']])), [...$auth, 'Idempotency-Key' => 'withdrawn-order-01'])->assertCreated();
        $placed = $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', OrderNotices::PLACED)->where('channel', 'sms')->sole());
        $this->assertNotSame(NotificationDelivery::SKIPPED, $placed->status);

        // Turned on again from the account, with the wording shown there.
        $this->shopCall('PUT', 'duka/account/marketing-consent', ['granted' => true], $auth)->assertUnprocessable()->assertJsonValidationErrors('wording_version');
        $this->shopCall('PUT', 'duka/account/marketing-consent', ['granted' => true, 'wording_version' => '2026_10'], $auth)->assertOk()->assertJsonPath('data.marketing_consent', true);
        $this->shopCall('PUT', 'duka/account/marketing-consent', ['granted' => false])->assertUnauthorized();
    }

    /** @return list<string> the numbers the fake SMS driver sent to */
    private function fakeSms(): array
    {
        return array_map(fn ($m) => $m->to, app(ChannelDrivers::class)->for('sms')->sent);
    }
}
