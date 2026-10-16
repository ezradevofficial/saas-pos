<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Ecommerce\Customers\CustomerCodeNotification;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\CustomerCode;
use Modules\Ecommerce\Models\Message;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Modules\Ecommerce\WhatsApp\FakeWhatsAppProvider;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-08, ECOM-23 to ECOM-25: WhatsApp messages in (verified, stored
// once), the inbox, replies, a chat turned into an order; customers'
// sign-in codes, sessions and accounts.
class WhatsAppAndCustomersTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
        FakeWhatsAppProvider::$sent = [];
    }

    public function test_inbound_messages_are_verified_and_stored_once(): void
    {
        $token = $this->api('POST', "ecommerce/stores/{$this->shop->id}/whatsapp-token")->assertCreated()->json('data.token');
        $body = json_encode(['messages' => [['id' => 'wamid.1', 'from' => '254712345678', 'name' => 'Wanjiku', 'text' => 'Two widgets please', 'at' => '2026-11-30T08:00:00Z']]]);
        $post = fn (string $sig, ?string $t = null) => $this->call('POST', '/api/v1/shop/whatsapp/'.($t ?? $token), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json'], $body);

        $post('bad')->assertUnauthorized()->assertJsonPath('code', 'webhook_unverified');
        $post(FakeWhatsAppProvider::sign($body))->assertOk()->assertJsonPath('data.received', 1);
        $post(FakeWhatsAppProvider::sign($body))->assertOk()->assertJsonPath('data.received', 0);
        $post(FakeWhatsAppProvider::sign($body), str_repeat('x', 40))->assertNotFound();

        $conversation = $this->inTenant(fn () => Conversation::query()->sole());
        $this->assertSame(['+254712345678', 'Wanjiku', 'open'], [$conversation->phone, $conversation->name, $conversation->status]);
        $this->assertSame(1, $this->inTenant(fn () => Message::query()->count()));

        // The inbox, a reply through the provider, and the chat as an order.
        $list = $this->api('GET', 'ecommerce/conversations', [], $this->manager)->assertOk()->json('data');
        $this->assertSame('Two widgets please', $list[0]['last_message']);
        $this->api('POST', "ecommerce/conversations/{$conversation->id}/reply", ['text' => 'Sure, KES 2,520.00 with delivery.'], $this->manager)->assertCreated();
        $this->assertSame('+254712345678', FakeWhatsAppProvider::$sent[0]['to']);

        $order = $this->api('POST', "ecommerce/conversations/{$conversation->id}/order", [
            ...$this->cart([[$this->widgetProduct, '2']]), 'payment' => 'cod', 'contact' => ['name' => 'Wanjiku', 'phone' => $conversation->phone],
            'address' => ['line' => 'Mpaka Road 12'], 'expected_total_minor' => '252000',
        ], $this->manager)->assertCreated()->json('data');
        $this->assertSame(['whatsapp', $conversation->id], [$order['channel'], $order['conversation_id']]);
        $this->assertSame('converted', $conversation->refresh()->status);

        // Another branch's manager does not see the inbox of this store.
        $this->assertSame([], $this->api('GET', 'ecommerce/conversations?status=all', [], $this->userWith('branch_manager', Scope::branch($this->branchB->id)))->json('data'));
    }

    public function test_a_customer_signs_in_with_a_code_and_sees_their_orders(): void
    {
        $challenge = $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712345678'])->assertCreated()->json('data');
        $this->assertSame('sms', $challenge['channel']);
        $sent = Notification::sentNotifications();
        $code = null;
        Notification::assertSentOnDemand(CustomerCodeNotification::class, function (CustomerCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->assertNotNull($sent);

        // A second code within a minute is refused (core's OTP send limits).
        $this->shopCall('POST', 'duka/auth/code', ['contact' => '0712345678'])->assertStatus(429);

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'code_invalid');
        $token = $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $code])->assertOk()->json('data.token');
        $this->assertStringStartsWith('ecs_', $token);
        // Single use.
        $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $code])->assertUnprocessable();

        // An order placed while signed in is in the account and tracked without its token.
        $auth = ['Authorization' => "Bearer {$token}"];
        $placed = $this->shopCall('POST', 'duka/orders', $this->checkout($this->cart([[$this->widgetProduct, '1']])), [...$auth, 'Idempotency-Key' => 'signed-in-order-01'])->assertCreated()->json('data');
        $account = $this->shopCall('GET', 'duka/account', [], $auth)->assertOk()->json('data');
        $this->assertSame([$placed['number']], array_column($account['orders'], 'number'));
        $this->assertSame('Mpaka Road 12', $account['addresses'][0]['line']);
        $this->shopCall('GET', "duka/orders/{$placed['number']}", [], $auth)->assertOk();

        // Signed out: the token stops working.
        $this->shopCall('POST', 'duka/auth/sign-out', [], $auth)->assertOk();
        $this->shopCall('GET', 'duka/account', [], $auth)->assertUnauthorized();
        $this->shopCall('GET', 'duka/account')->assertUnauthorized()->assertJsonPath('code', 'customer_session_required');
    }

    public function test_a_session_belongs_to_its_store(): void
    {
        $this->shopCall('POST', 'duka/auth/code', ['contact' => 'buyer@example.com'])->assertCreated();
        $code = null;
        Notification::assertSentOnDemand(CustomerCodeNotification::class, function (CustomerCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $id = $this->inTenant(fn () => CustomerCode::query()->sole()->id);
        $token = $this->shopCall('POST', 'duka/auth/verify', ['challenge_id' => $id, 'code' => $code])->assertOk()->json('data.token');

        // A second store of the tenant does not accept it.
        $this->inTenant(fn () => Store::create([...$this->shop->only(['company_id', 'branch_id', 'location_id', 'currency', 'price_list_id', 'payment_methods']),
            'handle' => 'second', 'name' => 'Second', 'status' => 'live']));
        $this->shopCall('GET', 'second', [], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();

        // An expired session neither.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(31));
        $this->shopCall('GET', 'duka/account', [], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_online_customers_are_listed_in_the_back_office(): void
    {
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated();

        $customers = $this->api('GET', 'ecommerce/customers', [], $this->manager)->assertOk()->json('data');
        $this->assertSame(['Wanjiku Kamau', '+254712345678', 1], [$customers[0]['name'], $customers[0]['phone'], $customers[0]['orders']]);
        $this->api('GET', 'ecommerce/customers', [], $this->riderUser)->assertForbidden();
    }
}
