<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Throwable;

/**
 * A live online store with a catalogue and delivery areas, a rider, and phone orders at every stage:
 * placed, accepted, handed to the rider (out for delivery), and delivered with cash waiting for a cash-up (ECOM-01 to ECOM-36).
 */
class EcommerceSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];
        $currency = $demo->ids['currency'];
        $branch = array_values($demo->ids['branches'])[0];
        $location = $demo->ids['locations'][0];

        if ($api->rows('ecommerce/stores') !== []) {
            return;
        }

        $list = collect($api->rows("companies/{$company}/price-lists"))->firstWhere('is_default', true);
        $cash = collect($api->rows("companies/{$company}/payment-methods"))->firstWhere('name', 'Cash KES');

        $store = $api->post('ecommerce/stores', [
            'company_id' => $company, 'branch_id' => $branch, 'location_id' => $location, 'handle' => 'demo-trading', 'name' => 'Demo Trading online', 'status' => 'live',
            'currency' => $currency, 'price_list_id' => $list['id'], 'delivery_enabled' => true, 'pickup_enabled' => true, 'pickup_location_ids' => [$location],
            'reservation_minutes' => 60, 'contact_phone' => '0722 600 100', 'contact_email' => 'orders@demotrading.example.com',
            'payment_methods' => [['kind' => 'cod', 'payment_method_id' => $cash['id']]],
        ])['data'];

        $api->post("ecommerce/stores/{$store['id']}/products", ['item_ids' => array_slice($demo->ids['items'], 0, 6), 'published' => true]);
        $products = array_column($api->rows("ecommerce/stores/{$store['id']}/products"), 'id');

        $zones = [];

        foreach ([['Westlands', '15000', '500000', 'Same day'], ['Nairobi CBD', '10000', '400000', 'Within 3 hours'], ['Kilimani', '20000', null, 'Same day']] as [$name, $fee, $free, $eta]) {
            $zones[] = $api->post("ecommerce/stores/{$store['id']}/zones", array_filter(['name' => $name, 'fee_minor' => $fee, 'free_over_minor' => $free, 'eta' => $eta]))['data']['id'];
        }

        $rider = $api->post('ecommerce/riders', [
            'company_id' => $company, 'user_id' => $demo->userId('rider'), 'name' => 'Juma Kariuki', 'phone' => '0712 600 201', 'vehicle' => 'Motorbike KMFB 331T',
        ])['data'];

        $place = function (int $product, int $qty, int $zone, string $name, string $phone, string $line) use ($api, $store, $products, $zones) {
            $cart = [
                'lines' => [['product_id' => $products[$product], 'qty' => (string) $qty]], 'fulfilment' => 'delivery', 'zone_id' => $zones[$zone],
            ];
            $total = $api->post("ecommerce/stores/{$store['id']}/quote", $cart)['data']['totals']['total_minor'];

            return $api->post('ecommerce/orders', $cart + [
                'store_id' => $store['id'], 'channel' => 'phone', 'payment' => 'cod', 'contact' => ['name' => $name, 'phone' => $phone],
                'address' => ['line' => $line, 'area' => 'Nairobi'], 'expected_total_minor' => (string) $total,
            ])['data'];
        };

        $advance = function (array $order, array $steps, bool $assign = true) use ($api, $rider) {
            foreach ($steps as $step) {
                if ($step === 'assign') {
                    $delivery = $api->get("ecommerce/orders/{$order['id']}")['data']['delivery'] ?? null;
                    $delivery !== null && $api->post("ecommerce/deliveries/{$delivery['id']}/assign", ['rider_id' => $rider['id']]);

                    continue;
                }

                $api->post("ecommerce/orders/{$order['id']}/{$step}");
            }
        };

        $place(0, 2, 0, 'Lucy Achieng', '0722 800 001', 'Argwings Kodhek Road, house 12');
        $advance($place(1, 3, 1, 'Brian Mutua', '0733 800 002', 'Moi Avenue, Plaza building'), ['accept', 'pick', 'ready', 'assign']);
        $advance($place(2, 1, 2, 'Naomi Cherono', '0711 800 003', 'Wood Avenue, Kilimani'), ['accept', 'ready', 'assign', 'dispatch']);

        $delivered = $place(3, 2, 0, 'Samuel Waweru', '0700 800 004', 'Ring Road, Westlands');
        $advance($delivered, ['accept', 'ready', 'assign', 'dispatch']);

        try {
            $api->post("ecommerce/orders/{$delivered['id']}/deliver");
        } catch (Throwable) {
            // The sample does not depend on the hand-over: the order stays out for delivery.
        }
    }
}
