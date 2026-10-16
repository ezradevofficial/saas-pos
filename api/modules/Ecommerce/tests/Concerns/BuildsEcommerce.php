<?php

namespace Modules\Ecommerce\Tests\Concerns;

use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Models\Zone;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with E-commerce active (BuildsOrganisation's Acme, KE, base
 * KES), reached at `acme-shop.shop.test` (APP_BASE_DOMAIN `shop.test`):
 * unit EA; a widget (stock item, the synthetic test VAT code at 16 %,
 * never a real rate; KES 1,000.00 exclusive on the default KES price
 * list), a service (KES 500.00, 16 %) and a "rate needed" gadget; M-Pesa
 * (provider mpesa_ke on the fake driver) and cash payment methods; the
 * live store `duka` at Outlet A (branch A) with delivery (zone Westlands,
 * KES 200.00, free from KES 5,000.00) and pickup at Outlet A; the three
 * products published; a branch manager at A, a rider (user with the Rider
 * template at A) and a cashier at B. Time is fixed at 2026-11-30 09:00 UTC.
 */
trait BuildsEcommerce
{
    use BuildsOrganisation;

    protected Uom $each;

    protected Item $widget;

    protected Item $service;

    protected Item $gadget;

    protected TaxCode $vat;

    protected PriceList $list;

    protected PaymentMethod $mpesa;

    protected PaymentMethod $cash;

    protected Store $shop;

    protected Zone $zone;

    protected Product $widgetProduct;

    protected Product $serviceProduct;

    protected Product $gadgetProduct;

    protected User $manager;

    protected User $riderUser;

    protected Rider $rider;

    protected string $host = 'acme-shop.shop.test';

    protected function setUpEcommerce(): void
    {
        Notification::fake();
        Mail::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30T09:00:00Z'));
        config(['branding.base_domain' => 'shop.test', 'payments.drivers.mpesa_ke' => 'fake', 'app.frontend_url' => 'https://app.shop.test']);
        $this->setUpOrganisation();
        $this->putJson('/api/v1/branding/settings', ['slug' => 'acme-shop'], $this->headersFor())->assertOk();

        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(ModuleRegistry::class)->activate('ecommerce');

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_T', 'name' => 'VAT test', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => '16', 'effective_from' => '2026-01-01']);
            $pending = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_P', 'name' => 'VAT pending', 'kind' => 'vat']);
            $goods = TaxCategory::create(['name' => 'Goods']);
            $waiting = TaxCategory::create(['name' => 'Waiting']);
            TaxCategoryCode::create(['tax_category_id' => $goods->id, 'company_id' => $this->acme->id, 'tax_code_id' => $this->vat->id]);
            TaxCategoryCode::create(['tax_category_id' => $waiting->id, 'company_id' => $this->acme->id, 'tax_code_id' => $pending->id]);

            $this->widget = Item::create(['code' => 'WIDGET', 'name' => 'Widget', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $goods->id]);
            $this->service = Item::create(['code' => 'SETUP', 'name' => 'Setup service', 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $goods->id]);
            $this->gadget = Item::create(['code' => 'GADGET', 'name' => 'Gadget', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $waiting->id]);
            $this->list = PriceList::create(['company_id' => $this->acme->id, 'name' => 'Online', 'currency' => 'KES', 'tax_inclusive' => false, 'is_default' => true]);

            foreach ([[$this->widget, '100000'], [$this->service, '50000'], [$this->gadget, '30000']] as [$item, $minor]) {
                ItemPrice::create(['price_list_id' => $this->list->id, 'item_id' => $item->id, 'uom_id' => $this->each->id, 'amount_minor' => $minor, 'currency' => 'KES', 'effective_from' => '2026-01-01']);
            }

            $this->mpesa = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'mobile_money', 'name' => 'M-Pesa', 'provider' => 'mpesa_ke', 'currency' => 'KES', 'position' => 1, 'active' => true]);
            $this->cash = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'cash', 'name' => 'Cash', 'currency' => 'KES', 'position' => 2, 'active' => true]);

            $this->shop = Store::create([
                'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'handle' => 'duka', 'name' => 'Duka Online',
                'status' => Store::LIVE, 'currency' => 'KES', 'price_list_id' => $this->list->id, 'delivery_enabled' => true, 'pickup_enabled' => true,
                'pickup_location_ids' => [$this->locationA->id], 'reservation_minutes' => 30, 'contact_phone' => '+254700000001', 'whatsapp_number' => '+254700000002',
                'payment_methods' => [['kind' => 'mpesa', 'payment_method_id' => $this->mpesa->id], ['kind' => 'cod', 'payment_method_id' => $this->cash->id]],
            ]);
            $this->zone = Zone::create(['store_id' => $this->shop->id, 'name' => 'Westlands', 'areas' => ['Westlands', 'Parklands'], 'fee_minor' => 20000, 'free_over_minor' => 500000, 'active' => true]);
            $this->widgetProduct = Product::create(['store_id' => $this->shop->id, 'item_id' => $this->widget->id, 'slug' => 'widget', 'description' => 'A fine widget.', 'published' => true, 'featured' => true, 'track_stock' => true]);
            $this->serviceProduct = Product::create(['store_id' => $this->shop->id, 'item_id' => $this->service->id, 'slug' => 'setup', 'published' => true, 'track_stock' => false]);
            $this->gadgetProduct = Product::create(['store_id' => $this->shop->id, 'item_id' => $this->gadget->id, 'slug' => 'gadget', 'published' => true, 'track_stock' => true]);
        });

        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->riderUser = $this->userWith('rider', Scope::branch($this->branchA->id));
        $this->rider = $this->inTenant(fn () => Rider::create(['company_id' => $this->acme->id, 'user_id' => $this->riderUser->id, 'name' => 'Juma Rider', 'phone' => '+254711000111', 'active' => true]));
    }

    protected function api(string $method, string $uri, array $body = [], ?User $as = null, array $headers = []): TestResponse
    {
        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $body, [...$this->headersFor($as ?? $this->owner), ...$headers]);
    }

    /** A storefront call on the tenant's host (no tenant token). */
    protected function shopCall(string $method, string $uri, array $body = [], array $headers = [], ?string $host = null): TestResponse
    {
        return $this->json($method, '/api/v1/shop/'.ltrim($uri, '/'), $body, ['X-Shop-Host' => $host ?? $this->host, 'Accept-Language' => 'en', ...$headers]);
    }

    /** @param list<array{0: Product, 1: string}> $lines */
    protected function cart(array $lines, array $extra = []): array
    {
        return [
            'lines' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'qty' => $line[1]], $lines),
            'fulfilment' => 'delivery',
            'zone_id' => $this->zone->id,
            ...$extra,
        ];
    }

    /** The checkout body for $cart at the server's quoted total. */
    protected function checkout(array $cart, string $payment = 'cod', array $extra = []): array
    {
        $total = $this->shopCall('POST', 'duka/quote', $cart)->assertOk()->json('data.totals.total_minor');

        return [
            ...$cart,
            'payment' => $payment,
            'contact' => ['name' => 'Wanjiku Kamau', 'phone' => '0712345678', 'email' => 'wanjiku@example.com'],
            'address' => ['line' => 'Mpaka Road 12', 'area' => 'Westlands'],
            'expected_total_minor' => $total,
            ...$extra,
        ];
    }

    /** Place an order on the storefront; returns the response's data. */
    protected function placeOrder(array $cart, string $payment = 'cod', ?string $key = null, array $extra = []): TestResponse
    {
        return $this->shopCall('POST', 'duka/orders', $this->checkout($cart, $payment, $extra), ['Idempotency-Key' => $key ?? 'key-'.Str::random(20)]);
    }

    /** Inventory switched on, with $qty widgets on hand at Outlet A (opening stock at KES 400.00 each). */
    protected function stockUp(string $qty, ?Item $item = null): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => ($item ?? $this->widget)->id, 'location_id' => $this->locationA->id, 'qty' => $qty, 'unit_cost' => '400'],
            $this->headersFor())->assertCreated();
    }

    protected function orderId(string $number): string
    {
        return $this->inTenant(fn () => Order::query()->where('number', $number)->value('id'));
    }
}
