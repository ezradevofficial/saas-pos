<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Illuminate\Support\Str;

/** A menu, a dining area with tables, kitchen and bar stations, and two open orders a waiter has sent to the kitchen display (RES-01 to RES-10). */
class RestaurantSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $location = $demo->ids['locations'][0];

        if ($api->rows('restaurant/areas') !== []) {
            return;
        }

        $food = $api->post('item-categories', ['name' => 'Kitchen'])['data']['id'];
        $drinks = $api->post('item-categories', ['name' => 'Bar'])['data']['id'];
        $menu = [
            'nyama' => $demo->item('NYAMA', 'Nyama choma with kachumbari', 'non_stock', 85000, $food),
            'pilau' => $demo->item('PILAU', 'Beef pilau', 'non_stock', 45000, $food),
            'ugali' => $demo->item('UGALI', 'Ugali and sukuma wiki', 'non_stock', 30000, $food),
            'tusker' => $demo->item('TUSKER', 'Tusker lager 500 ml', 'non_stock', 35000, $drinks),
            'soda' => $demo->item('SODA', 'Soda 300 ml', 'non_stock', 12000, $drinks),
        ];

        $area = $api->post('restaurant/areas', ['location_id' => $location, 'name' => 'Main hall'])['data']['id'];
        $tables = [];

        foreach (['T1' => 4, 'T2' => 4, 'T3' => 6, 'T4' => 2] as $name => $seats) {
            $tables[$name] = $api->post('restaurant/tables', ['location_id' => $location, 'area_id' => $area, 'name' => $name, 'seats' => $seats])['data']['id'];
        }

        $api->post('restaurant/stations', ['location_id' => $location, 'name' => 'Kitchen', 'kind' => 'kitchen', 'is_default' => true, 'category_ids' => [$food]]);
        $api->post('restaurant/stations', ['location_id' => $location, 'name' => 'Bar', 'kind' => 'bar', 'category_ids' => [$drinks]]);

        $till = $demo->pairDevice($location, 'Waiter tablet');
        $waiter = $demo->userId('waiter');
        $vat = $demo->ids['vat'];

        $prices = ['nyama' => 85000, 'pilau' => 45000, 'ugali' => 30000, 'tusker' => 35000, 'soda' => 12000];
        $make = function (string $key, string $qty, int $minutesAgo) use ($menu, $prices, $vat, $waiter) {
            return [
                'id' => (string) Str::uuid7(), 'item_id' => $menu[$key]['id'], 'name' => $menu[$key]['name'], 'qty' => $qty,
                'unit_price_minor' => (string) $prices[$key], 'tax_code_id' => $vat['id'], 'tax_rate' => $vat['rate'], 'tax_inclusive' => true,
                'course' => 1, 'seat' => null, 'note' => null, 'modifiers' => [],
                'created_at' => now()->subMinutes($minutesAgo + 2)->toIso8601String(),
                'sent_at' => now()->subMinutes($minutesAgo)->toIso8601String(), 'sent_by' => $waiter, 'round' => 1,
            ];
        };

        $order = fn (string $table, int $covers, array $lines, int $openedAgo) => [
            'id' => (string) Str::uuid7(), 'kind' => 'table', 'table_id' => $tables[$table], 'tab_name' => null, 'customer_id' => null,
            'waiter_id' => $waiter, 'covers' => $covers, 'currency' => 'KES',
            'opened_at' => now()->subMinutes($openedAgo)->toIso8601String(), 'updated_at' => now()->subMinutes(3)->toIso8601String(), 'updated_by' => $waiter,
            'lines' => $lines, 'resends' => [],
        ];

        $till['client']->post('restaurant/orders/sync', ['orders' => [
            $order('T1', 3, [$make('nyama', '2', 14), $make('ugali', '2', 14), $make('tusker', '3', 12)], 30),
            $order('T3', 4, [$make('pilau', '4', 6), $make('soda', '4', 6)], 18),
        ]]);
    }
}
