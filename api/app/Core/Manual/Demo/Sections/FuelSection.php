<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Illuminate\Support\Str;

/**
 * A forecourt at the main outlet: petrol and diesel, tanks with strapping charts, two pumps, today's prices, a tanker delivery, dips,
 * and a shift closed with a cash shortage that waits for the station manager (FUEL-01 to FUEL-26).
 */
class FuelSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];
        $location = $demo->ids['locations'][0];

        if ($api->rows('fuel/stations') !== []) {
            return;
        }

        $api->post('fuel/stations', ['location_id' => $location, 'currency' => 'KES']);

        $products = [];

        foreach ([['PMS', 'Super petrol', 'petrol', 19500, 'Super'], ['AGO', 'Automotive diesel', 'diesel', 18500, 'Diesel']] as [$code, $name, $type, $price, $grade]) {
            $item = $demo->item($code, $name, 'stock', $price, null, 'L');
            $products[$code] = $api->post('fuel/products', ['company_id' => $company, 'item_id' => $item['id'], 'fuel_type' => $type, 'grade' => $grade])['data']['id'];
            $api->post('fuel/prices', ['location_id' => $location, 'product_id' => $products[$code], 'price_minor' => $price, 'reference' => 'EPRA pump price this month']);
        }

        $charts = [
            'PMS' => [[0, 0], [50, 6000], [100, 13000], [150, 21000], [200, 30000]],
            'AGO' => [[0, 0], [50, 4000], [100, 9000], [150, 14500], [200, 20000]],
        ];
        $tanks = [];

        foreach ([['PMS', 'T1', 'Petrol tank 1', '30000'], ['AGO', 'T2', 'Diesel tank 1', '20000']] as [$product, $code, $name, $capacity]) {
            $tanks[$product] = $api->post('fuel/tanks', ['location_id' => $location, 'product_id' => $products[$product], 'code' => $code, 'name' => $name, 'capacity_litres' => $capacity, 'tolerance_litres' => '50'])['data']['id'];
            $api->post("fuel/tanks/{$tanks[$product]}/strapping", ['points' => array_map(fn ($p) => ['dip_cm' => (string) $p[0], 'litres' => (string) $p[1]], $charts[$product]), 'note' => 'Supplier calibration chart'])['data'];
        }

        $nozzles = [];

        foreach ([['P1', 'Pump 1', 'PMS'], ['P2', 'Pump 2', 'AGO']] as [$code, $name, $product]) {
            $pump = $api->post('fuel/pumps', ['location_id' => $location, 'code' => $code, 'name' => $name])['data']['id'];
            $nozzles[$product] = $api->post('fuel/nozzles', ['pump_id' => $pump, 'tank_id' => $tanks[$product], 'code' => "{$code}-A"])['data']['id'];
        }

        // A tanker offloads into the petrol tank: dips before and after, 11,500 L on the note.
        $api->post('fuel/deliveries', [
            'tank_id' => $tanks['PMS'], 'supplier_id' => $demo->ids['suppliers'][2] ?? null, 'note_number' => 'KP-884012', 'note_litres' => '11500',
            'before_dip_cm' => '40', 'after_dip_cm' => '118', 'unit_cost_minor' => 17200, 'currency' => 'KES',
        ] + []);
        $api->post('fuel/dips', ['tank_id' => $tanks['AGO'], 'dip_cm' => '132.5', 'water_cm' => '0.5']);

        $till = $demo->pairDevice($location, 'Forecourt till');
        $attendant = $demo->userId('forecourt_attendant');
        $supervisor = $demo->userId('shift_supervisor');

        $till['client']->post('fuel/device/shifts', ['shifts' => [[
            'id' => (string) Str::uuid7(),
            'opening' => [
                'opened_by_id' => $supervisor, 'opened_at' => now()->subHours(9)->toIso8601String(), 'actor_proof' => $demo->actorProof($till, $supervisor),
                'nozzles' => [
                    ['nozzle_id' => $nozzles['PMS'], 'attendant_id' => $attendant, 'reading' => '1000.000'],
                    ['nozzle_id' => $nozzles['AGO'], 'attendant_id' => $attendant, 'reading' => '5000.000'],
                ],
            ],
            'closing' => [
                'closed_by_id' => $supervisor, 'closed_at' => now()->subHour()->toIso8601String(), 'actor_proof' => $demo->actorProof($till, $supervisor),
                'nozzles' => [
                    ['nozzle_id' => $nozzles['PMS'], 'reading' => '1100.000'],
                    ['nozzle_id' => $nozzles['AGO'], 'reading' => '5060.000'],
                ],
                // 100 L petrol and 60 L diesel come to KES 30,600.00; KES 29,000.00 is handed in.
                'tenders' => [['attendant_id' => $attendant, 'tenders' => [['kind' => 'cash', 'amount_minor' => '2900000']]]],
            ],
        ]]]);

        $api->as('shift_supervisor')->post('fuel/meter-resets', [
            'nozzle_id' => $nozzles['PMS'], 'reading_before' => '1100.000', 'reading_after' => '0.000', 'reason' => 'Pump 1 meter replaced after a fault',
        ]);
    }
}
