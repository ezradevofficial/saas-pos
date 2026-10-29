<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** A van with its rep, a Tuesday and Friday route through four kiosks, and a morning load released from the main outlet's stock (VAN-01 to VAN-10). */
class VansalesSection
{
    use WaitsForApproval;

    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $branch = array_values($demo->ids['branches'])[0];

        if ($api->rows('van-sales/vans') !== []) {
            return;
        }

        $customers = [];

        foreach ([['Mama Njeri Kiosk', 'Kawangware'], ['Karibu General Shop', 'Kibera'], ['Baraka Wholesalers', 'Githurai'], ['Jirani Minimart', 'Kasarani']] as [$name, $area]) {
            $customers[] = $api->post('parties', [
                'kind' => 'organisation', 'name' => $name, 'roles' => ['customer'], 'addresses' => [['line1' => $area, 'city' => 'Nairobi', 'country' => 'KE']],
            ])['data']['id'];
        }

        $van = $api->post('van-sales/vans', ['branch_id' => $branch, 'name' => 'Van KDG 214X', 'plate' => 'KDG 214X', 'rep_id' => $demo->userId('van_rep')])['data'];
        $route = $api->post('van-sales/routes', ['branch_id' => $branch, 'van_id' => $van['id'], 'name' => 'Nairobi west route', 'visit_days' => [2, 5]])['data'];
        $api->put("van-sales/routes/{$route['id']}/stops", ['stops' => array_map(fn ($id) => ['customer_id' => $id], $customers)]);

        $load = $api->post('van-sales/loads', [
            'van_id' => $van['id'], 'from_location_id' => $demo->ids['locations'][0], 'note' => 'Morning load for the Tuesday route',
            'lines' => [['item_id' => $demo->ids['items'][0], 'qty' => 24], ['item_id' => $demo->ids['items'][1], 'qty' => 12], ['item_id' => $demo->ids['items'][4], 'qty' => 10], ['item_id' => $demo->ids['items'][5], 'qty' => 8]],
        ])['data'];
        $this->approveUntil($demo, 'branch_manager', fn () => $api->get("van-sales/loads/{$load['id']}")['data']['status'] === 'approved');
        $api->post("van-sales/loads/{$load['id']}/release");
    }
}
