<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** Opening stock of the sample items at both branches (INV-18), then a transfer between them (INV-07). */
class InventorySection
{
    public function run(DemoData $demo): void
    {
        if ($demo->api->rows('inventory/stock') !== []) {
            return;
        }

        $costs = ['40.00', '55.00', '80.00', '100.00', '150.00', '120.00', '105.00', '230.00', '60.00', '170.00', '45.00', '12.00'];

        foreach ($demo->ids['locations'] as $n => $location) {
            foreach ($demo->ids['items'] as $i => $item) {
                $demo->api->post('inventory/opening-stock', [
                    'location_id' => $location,
                    'item_id' => $item,
                    'qty' => (string) (60 + (($i * 17 + $n * 40) % 90)),
                    'unit_cost' => $costs[$i % count($costs)],
                ]);
            }
        }
    }
}
