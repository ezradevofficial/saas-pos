<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Ecommerce\Customers\Customers;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Modules\Loyalty\Contracts\OnlineLoyalty;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\Programme;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-08, ECOM-10, ECOM-25, ECOM-33: who an order belongs to and what it may
// spend. A guest checkout never reaches an existing customer (a contact is
// added only by a verified code), party-bound loyalty needs a session,
// coupons are counted atomically, sign-in codes and gift card lookups cost
// an attacker, and a chat's order keeps the chat's phone.
class CheckoutSecurityTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_a_guest_order_never_attaches_its_contact_to_an_existing_customer(): void
    {
        // The victim signs in by email and orders.
        $victim = $this->shopSignIn('victim@example.com');
        $theirs = $this->shopCall('POST', 'duka/orders', $this->checkout($this->cart([[$this->widgetProduct, '1']])), [...$victim, 'Idempotency-Key' => 'victim-order-000001'])
            ->assertCreated()->json('data.number');

        // The attacker checks out as a guest with their own phone and the victim's email.
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), extra: ['contact' => ['name' => 'Mallory', 'phone' => '0712999888', 'email' => 'victim@example.com']])->assertCreated();
        $this->assertNull($this->inTenant(fn () => Customer::query()->where('email', 'victim@example.com')->value('phone')), 'the victim record gained no phone');

        // The attacker's SMS sign-in reaches a customer of their own, never the victim's account.
        $attacker = $this->shopSignIn('0712999888');
        $account = $this->shopCall('GET', 'duka/account', [], $attacker)->assertOk()->json('data');
        $this->assertNotContains($theirs, array_column($account['orders'], 'number'));
        $this->assertSame([], $account['addresses']);
        $this->assertNull($account['customer']['email'] ?? null);
        $this->shopCall('GET', "duka/orders/{$theirs}", [], $attacker)->assertNotFound();
    }

    public function test_points_need_the_members_own_session(): void
    {
        $this->withPoints();
        $member = $this->shopSignIn('0712345678');
        $this->inTenant(function () {
            $party = app(Customers::class)->party(Customer::query()->where('phone', '+254712345678')->sole(), $this->acme);
            $account = app(Ledgers::class)->pointsAccount($this->acme->id, $party);
            app(Ledgers::class)->points(app(Ledgers::class)->lockAccount($account->id), 'adjust', '500', ['source_type' => 'test', 'source_id' => $party]);
        });

        // A guest using the member's phone cannot spend the member's points.
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']], ['points' => '100']))->assertUnprocessable()->assertJsonPath('code', 'points_unavailable');
        $this->shopCall('POST', 'duka/quote', $this->cart([[$this->widgetProduct, '1']], ['points' => '100']))->assertOk()->assertJsonPath('data.problems.0.code', 'points_unavailable');

        // The member, signed in, can.
        $this->shopCall('POST', 'duka/orders', $this->checkout($this->cart([[$this->widgetProduct, '1']], ['points' => '100'])), [...$member, 'Idempotency-Key' => 'member-points-000001'])
            ->assertCreated()->assertJsonPath('data.totals.loyalty_paid_minor', '1000');
    }

    public function test_a_coupon_is_counted_atomically_and_given_back_on_cancel(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('loyalty'));
        $promotion = $this->api('POST', 'loyalty/promotions', ['company_id' => $this->acme->id, 'name' => 'Ten off', 'type' => 'percent', 'percent' => '10', 'priority' => 1,
            'status' => 'active', 'requires_coupon' => true, 'targets' => ['items' => [$this->widget->id]]])->assertCreated()->json('data.id');
        $this->api('POST', "loyalty/promotions/{$promotion}/coupons", ['codes' => ['SAVE10'], 'max_uses' => 1])->assertCreated();

        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']], ['coupon' => 'SAVE10']))->assertCreated()->json('data');
        $uses = fn () => $this->inTenant(fn () => Coupon::query()->where('code', 'SAVE10')->value('uses'));
        $this->assertSame(1, $uses());

        // A second order that priced the coupon before the first used it: no row is updated, refused.
        $this->expectRefusal(fn () => $this->inTenant(fn () => app(OnlineLoyalty::class)->useCoupons($this->acme->id, ['SAVE10'])), 'coupon_used_up');
        $this->assertSame(1, $uses());

        // Cancelled: the use comes back and the coupon works again.
        $this->api('POST', 'ecommerce/orders/'.$this->orderId($placed['number']).'/cancel', ['reason' => 'Changed mind'], $this->manager)->assertOk();
        $this->assertSame(0, $uses());
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']], ['coupon' => 'SAVE10']))->assertCreated();
    }

    public function test_sign_in_codes_are_capped_per_store_and_verified_under_their_own_limit(): void
    {
        config(['ecommerce.shop.codes_per_store_per_hour' => 2, 'ecommerce.shop.challenge_after' => 100]);
        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000601'])->assertCreated();
        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000602'])->assertCreated();
        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000603'])->assertStatus(429);

        config(['ecommerce.shop.verify_per_ip_per_minute' => 2]);
        $verify = fn () => $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => '01937a52-0000-7000-8000-000000000001', 'code' => '123456']);
        $verify()->assertUnprocessable();
        $verify()->assertUnprocessable();
        $verify()->assertStatus(429);
    }

    /** AUTH-01, ECOM-08: in email-only mode (no SMS provider) a code by SMS is refused; email works. */
    public function test_a_sign_in_code_by_sms_is_refused_in_email_only_mode(): void
    {
        config(['services.sms.driver' => 'none']);

        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000621'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'sms_unavailable')
            ->assertJsonValidationErrors(['contact' => __('auth.sms_unavailable.use_email')]);
        $this->shopCall('POST', 'duka/auth/code', ['contact' => 'wanjiru@example.com'])->assertCreated();
    }

    /** NOT-01, ECOM-20: in email-only mode an order update's SMS is skipped and goes by WhatsApp where configured. */
    public function test_in_email_only_mode_order_updates_skip_sms_and_go_by_whatsapp(): void
    {
        config(['services.sms.driver' => 'none']);

        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated();

        $placed = $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', OrderNotices::PLACED)->get()->keyBy('channel'));
        $this->assertSame(['skipped', 'sms_unavailable'], [$placed['sms']->status, $placed['sms']->reason]);
        $this->assertSame($placed['sms']->recipient, $placed['whatsapp']->recipient);
        $this->assertNotSame(NotificationDelivery::SKIPPED, $placed['whatsapp']->status);
    }

    public function test_after_a_few_codes_from_one_address_a_challenge_is_needed(): void
    {
        config(['ecommerce.shop.challenge_after' => 1, 'ecommerce.shop.challenge_difficulty' => 2]);
        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000611'])->assertCreated();

        $refused = $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000612'])->assertStatus(428)->assertJsonPath('code', 'challenge_required');
        $challenge = $refused->json('challenge');
        $this->assertSame(2, $challenge['difficulty']);
        // A wrong answer uses the challenge up; the refusal brings a new one.
        $challenge = $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000612', 'challenge' => ['id' => $challenge['id'], 'solution' => 'wrong']])->assertStatus(428)->json('challenge');

        $solution = 0;
        while (! str_starts_with(hash('sha256', $challenge['nonce'].':'.$solution), str_repeat('0', $challenge['difficulty']))) {
            $solution++;
        }

        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000612', 'challenge' => ['id' => $challenge['id'], 'solution' => (string) $solution]])->assertCreated();
        // Single use.
        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712000613', 'challenge' => ['id' => $challenge['id'], 'solution' => (string) $solution]])->assertStatus(428);
    }

    public function test_gift_card_lookups_fail_into_a_limit_and_show_only_what_is_applied(): void
    {
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('loyalty');
            $method = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'voucher', 'name' => 'Gift card', 'currency' => 'KES', 'active' => true, 'position' => 5]);
            $this->shop->forceFill(['payment_methods' => [...$this->shop->payment_methods, ['kind' => 'gift_card', 'payment_method_id' => $method->id]]])->save();
            GiftCard::create(['company_id' => $this->acme->id, 'code_hash' => GiftCards::hash('GIFT-RICH-0001'), 'last4' => '0001', 'kind' => 'gift_card', 'currency' => 'KES',
                'initial_minor' => 9_000_000, 'balance_minor' => 9_000_000, 'status' => GiftCard::ACTIVE, 'issued_at' => now()]);
        });
        config(['ecommerce.shop.gift_card_failures_per_ip_per_hour' => 2, 'ecommerce.shop.gift_card_failures_per_store_per_hour' => 50]);

        $quote = $this->shopCall('POST', 'duka/quote', $this->cart([[$this->widgetProduct, '1']], ['gift_card' => 'GIFT-RICH-0001']))->assertOk()->json('data');
        $this->assertSame(['136000', '0'], [$quote['loyalty']['gift_card_minor'], $quote['due_minor']], 'applied up to the total only');
        $this->assertStringNotContainsString('9000000', json_encode($quote));

        $guess = fn (string $code) => $this->shopCall('POST', 'duka/quote', $this->cart([[$this->widgetProduct, '1']], ['gift_card' => $code]));
        $guess('GIFT-GUESS-0001')->assertOk()->assertJsonPath('data.problems.0.code', 'gift_card_unusable');
        $guess('GIFT-GUESS-0002')->assertOk();
        $guess('GIFT-RICH-0001')->assertStatus(429);
        RateLimiter::clear('ecom-gift-card|ip|127.0.0.1');
    }

    public function test_a_chat_order_keeps_the_chats_phone(): void
    {
        $conversation = $this->inTenant(fn () => Conversation::create(['store_id' => $this->shop->id, 'phone' => '+254711000401', 'name' => 'Chat', 'status' => 'open']));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $body = [...$this->cart([[$this->widgetProduct, '1']]), 'payment' => 'cod', 'contact' => ['name' => 'Someone', 'phone' => '0712999777'], 'address' => ['line' => 'Mpaka Road 12'],
            'expected_total_minor' => '136000'];
        $order = $this->api('POST', "ecommerce/conversations/{$conversation->id}/order", $body, $manager)->assertCreated()->json('data');
        $this->assertSame('+254711000401', $order['contact_phone']);
        $this->assertSame('converted', $this->inTenant(fn () => $conversation->refresh()->status));
        $this->api('POST', "ecommerce/conversations/{$conversation->id}/order", $body, $manager)->assertStatus(409)->assertJsonPath('code', 'conversation_converted');
        $this->assertSame(1, $this->inTenant(fn () => Order::query()->count()));
    }

    private function withPoints(): void
    {
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('loyalty');
            Programme::create(['company_id' => $this->acme->id, 'active' => true, 'currency' => 'KES', 'earn_points' => 1, 'earn_spend_minor' => '10000', 'point_value_minor' => '10', 'min_redeem_points' => '0']);
            $points = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'points', 'name' => 'Points', 'currency' => 'KES', 'active' => true, 'position' => 4]);
            $this->shop->forceFill(['payment_methods' => [...$this->shop->payment_methods, ['kind' => 'points', 'payment_method_id' => $points->id]]])->save();
        });
    }

    private function expectRefusal(callable $fn, string $code): void
    {
        try {
            $fn();
        } catch (HttpResponseException|ApiException $e) {
            $this->assertSame($code, $e instanceof ApiException ? $e->errorCode : null);

            return;
        }

        $this->fail("expected {$code}");
    }
}
