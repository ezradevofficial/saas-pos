<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-02 to ECOM-13, ECOM-21: the public storefront: tenant resolution by
// host and handle, the catalogue, server-side pricing, tampered totals,
// idempotent placement and tracking.
class StorefrontTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_the_store_is_found_by_its_host_and_handle_only(): void
    {
        // ECOM-06: the tenant's subdomain and the live store's handle.
        $store = $this->shopCall('GET', 'duka')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->json('data');
        $this->assertSame(['Duka Online', 'KES', ['mpesa', 'cod']], [$store['name'], $store['currency'], $store['payment_kinds']]);
        $this->assertSame('Westlands', $store['zones'][0]['name']);
        $this->assertSame('20000', $store['zones'][0]['fee_minor']);

        // Another host, an unknown host, no host, an unknown handle: not found.
        $other = $this->otherTenant();
        $this->putJson('/api/v1/branding/settings', ['slug' => 'other-shop'], $this->bearer($this->tokenFor($other['user'])))->assertOk();
        $this->shopCall('GET', 'duka', host: 'other-shop.shop.test')->assertNotFound();
        $this->shopCall('GET', 'duka', host: 'nobody.shop.test')->assertNotFound();
        $this->json('GET', '/api/v1/shop/duka')->assertNotFound();
        $this->shopCall('GET', 'missing')->assertNotFound();

        // A draft store and a tenant without the module are not found either.
        $this->inTenant(fn () => $this->shop->forceFill(['status' => Store::DRAFT])->save());
        $this->shopCall('GET', 'duka')->assertNotFound();
        $this->inTenant(fn () => $this->shop->forceFill(['status' => Store::LIVE])->save());
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('ecommerce'));
        $this->shopCall('GET', 'duka')->assertNotFound();
    }

    public function test_a_back_office_token_is_refused_on_the_storefront(): void
    {
        // ECOM-07: only customer sessions; a tenant user's token is not one.
        $this->shopCall('GET', 'duka', headers: $this->headersFor())->assertUnauthorized()->assertJsonPath('code', 'customer_session_invalid');
        $this->shopCall('GET', 'duka', headers: ['Authorization' => 'Bearer ecs_'.str_repeat('a', 48)])->assertUnauthorized();
    }

    public function test_the_catalogue_shows_prices_availability_and_reasons(): void
    {
        $products = collect($this->shopCall('GET', 'duka/products')->assertOk()->json('data'))->keyBy('slug');

        $this->assertSame(['widget', 'gadget', 'setup'], $products->keys()->all(), 'featured first, then position and slug');
        $this->assertSame(['100000', 'KES', true, 'in_stock'], [$products['widget']['price_minor'], $products['widget']['currency'], $products['widget']['orderable'], $products['widget']['availability']['state']]);
        // ECOM-03: a code without a confirmed rate is shown unavailable with its reason, never priced with an invented rate.
        $this->assertSame([false, 'tax_rate_missing'], [$products['gadget']['orderable'], $products['gadget']['unavailable_reason']]);
        $this->assertStringContainsString('https://wa.me/?text=', $products['widget']['share']['whatsapp']);

        $one = $this->shopCall('GET', 'duka/products/widget')->assertOk()->json('data');
        $this->assertSame('A fine widget.', $one['description']);
        $this->shopCall('GET', 'duka/products/nothing')->assertNotFound();
    }

    public function test_the_quote_is_computed_by_the_server_in_minor_units(): void
    {
        // Widget KES 1,000.00 × 2 + service KES 500.00, 16 % exclusive; delivery KES 200.00 without a fee tax code.
        $quote = $this->shopCall('POST', 'duka/quote', $this->cart([[$this->widgetProduct, '2'], [$this->serviceProduct, '1']]))->assertOk()->json('data');

        $this->assertSame(['200000', '50000'], array_column($quote['lines'], 'gross_minor'));
        $this->assertSame(['32000', '8000'], array_column($quote['lines'], 'tax_minor'));
        $this->assertSame(['subtotal_minor' => '250000', 'discount_minor' => '0', 'tax_minor' => '40000', 'delivery_fee_minor' => '20000', 'delivery_tax_minor' => '0', 'total_minor' => '310000'], $quote['totals']);
        $this->assertSame('310000', $quote['due_minor']);
        $this->assertSame([], $quote['problems']);

        // Free delivery from KES 5,000.00 of goods.
        $big = $this->shopCall('POST', 'duka/quote', $this->cart([[$this->widgetProduct, '5']]))->json('data');
        $this->assertSame(['0', '580000'], [$big['totals']['delivery_fee_minor'], $big['totals']['total_minor']]);

        // Problems are listed: a rate needed, a bad quantity, no zone.
        $bad = $this->shopCall('POST', 'duka/quote', ['lines' => [['product_id' => $this->gadgetProduct->id, 'qty' => '1'], ['product_id' => $this->widgetProduct->id, 'qty' => '1.5']], 'fulfilment' => 'delivery'])->json('data');
        $this->assertSame(['tax_rate_missing', 'qty_invalid', 'zone_required'], array_column($bad['problems'], 'code'));
    }

    public function test_a_tampered_total_is_refused_and_nothing_is_stored(): void
    {
        // ECOM-12: the client says KES 1.00; the server's total is refused with the new quote.
        $body = $this->checkout($this->cart([[$this->widgetProduct, '1']]));
        $body['expected_total_minor'] = '100';
        $body['lines'][0]['unit_price_minor'] = '1';
        $body['totals'] = ['total_minor' => '100'];

        $this->shopCall('POST', 'duka/orders', $body, ['Idempotency-Key' => 'tampered-key-0001'])
            ->assertStatus(409)->assertJsonPath('code', 'total_changed')->assertJsonPath('quote.totals.total_minor', '136000');

        $this->assertSame(0, $this->inTenant(fn () => Order::query()->count()));
    }

    public function test_placement_is_idempotent_and_needs_a_key(): void
    {
        $cart = $this->cart([[$this->widgetProduct, '1']]);
        $body = $this->checkout($cart);

        $this->shopCall('POST', 'duka/orders', $body)->assertStatus(428)->assertJsonPath('code', 'idempotency_key_required');

        $first = $this->shopCall('POST', 'duka/orders', $body, ['Idempotency-Key' => 'same-key-0000001'])->assertCreated()->json('data');
        $again = $this->shopCall('POST', 'duka/orders', $body, ['Idempotency-Key' => 'same-key-0000001'])->assertOk()->json('data');

        $this->assertSame($first['number'], $again['number']);
        $this->assertNull($again['tracking_token'], 'the token is given once');
        $this->assertSame(1, $this->inTenant(fn () => Order::query()->count()));
        $this->assertMatchesRegularExpression('/^WEB-A-2026-0000\d$/', $first['number']);
        $this->assertSame(['placed', 'cod', '136000'], [$first['status'], $first['payment_status'], $first['totals']['total_minor']]);

        // The same key with another body is refused.
        $other = $this->checkout($this->cart([[$this->widgetProduct, '2']]));
        $this->shopCall('POST', 'duka/orders', $other, ['Idempotency-Key' => 'same-key-0000001'])->assertStatus(409)->assertJsonPath('code', 'idempotency_mismatch');
    }

    public function test_an_order_is_tracked_with_its_token_only(): void
    {
        $order = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data');

        $tracked = $this->shopCall('GET', 'duka/orders/'.$order['number'].'?t='.$order['tracking_token'])->assertOk()->json('data');
        $this->assertSame(['placed', 'cod'], [$tracked['status'], $tracked['payment_status']]);
        $this->assertSame('Widget', $tracked['lines'][0]['name']);

        $this->shopCall('GET', 'duka/orders/'.$order['number'])->assertNotFound();
        $this->shopCall('GET', 'duka/orders/'.$order['number'].'?t='.str_repeat('x', 40))->assertNotFound();
    }
}
