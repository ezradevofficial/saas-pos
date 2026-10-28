<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** Three Kenyan suppliers, one purchase order received into the main outlet, one waiting for approval. */
class ProcurementSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;

        if ($api->rows('procurement/orders') !== []) {
            return;
        }

        $suppliers = [];

        foreach ([['Unga Millers Limited', 'Nairobi', '0722 100 200'], ['Kenya Dairy Wholesalers', 'Nakuru', '0733 200 300'], ['Coast Beverages Distributors', 'Mombasa', '0711 300 400']] as [$name, $city, $phone]) {
            $existing = collect($api->rows('parties', ['search' => $name]))->firstWhere('name', $name);
            $suppliers[] = $existing['id'] ?? $api->post('parties', [
                'kind' => 'organisation',
                'name' => $name,
                'roles' => ['supplier'],
                'phones' => [['number' => $phone]],
                'addresses' => [['line1' => 'Industrial Area', 'city' => $city, 'country' => 'KE']],
                'payment_terms_days' => 30,
            ])['data']['id'];
        }

        $demo->ids['suppliers'] = $suppliers;
        $branch = array_values($demo->ids['branches'])[0];
        $location = $demo->ids['locations'][0];
        $items = $demo->ids['items'];

        $order = $api->post('procurement/orders', [
            'branch_id' => $branch,
            'party_id' => $suppliers[0],
            'location_id' => $location,
            'lines' => [
                ['item_id' => $items[5], 'qty' => '200', 'unit_price_minor' => '11500'],
                ['item_id' => $items[4], 'qty' => '100', 'unit_price_minor' => '14000'],
                ['item_id' => $items[6], 'qty' => '150', 'unit_price_minor' => '10000'],
            ],
        ])['data'];

        $api->post("procurement/orders/{$order['id']}/submit");
        $demo->approveAll('branch_manager');
        $api->post("procurement/orders/{$order['id']}/send");

        $receipt = $api->post('procurement/receipts', [
            'order_id' => $order['id'],
            'location_id' => $location,
            'delivery_note' => 'DN-20418',
            'lines' => array_map(fn ($line) => ['order_line_id' => $line['id'], 'received_qty' => $line['qty']], $order['lines']),
        ])['data'];
        $api->post("procurement/receipts/{$receipt['id']}/post");

        // A second order waits for the branch manager, so the approvals inbox has an item.
        $second = $api->post('procurement/orders', [
            'branch_id' => $branch,
            'party_id' => $suppliers[1],
            'location_id' => $location,
            'lines' => [['item_id' => $items[1], 'qty' => '300', 'unit_price_minor' => '5200']],
        ])['data'];
        $api->post("procurement/orders/{$second['id']}/submit");
    }
}
