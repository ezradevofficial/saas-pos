<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** Two room types, ten rooms, rate plans in KES, reservations and one guest checked in (HOT-01 to HOT-12). */
class HotelSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];
        $branch = array_values($demo->ids['branches'])[0];

        if ($api->rows('hotel/room-types') !== []) {
            return;
        }

        $types = [];

        foreach ([['STD', 'Standard room', 2, 'One double bed'], ['DLX', 'Deluxe room', 3, 'One king bed and a sofa bed']] as [$code, $name, $max, $beds]) {
            $types[$code] = $api->post('hotel/room-types', [
                'company_id' => $company, 'code' => $code, 'name' => $name, 'base_adults' => 2, 'max_adults' => $max, 'max_children' => 1, 'max_occupancy' => $max + 1, 'beds' => $beds,
            ])['data']['id'];
        }

        $rooms = [];

        foreach (['STD' => [101, 102, 103, 104, 105, 106], 'DLX' => [201, 202, 203, 204]] as $code => $numbers) {
            foreach ($numbers as $number) {
                $rooms[$number] = $api->post('hotel/rooms', [
                    'branch_id' => $branch, 'room_type_id' => $types[$code], 'number' => (string) $number, 'floor' => (string) intdiv($number, 100),
                ])['data']['id'];
            }
        }

        $plans = [];

        foreach ([['STD', 'STD-BB', 'Standard, bed and breakfast', 'bed_breakfast', 650000, 750000], ['DLX', 'DLX-BB', 'Deluxe, bed and breakfast', 'bed_breakfast', 980000, 1100000], ['STD', 'STD-RO', 'Standard, room only', 'room_only', 550000, 650000]] as [$type, $code, $name, $board, $weekday, $weekend]) {
            $plans[$code] = $api->post('hotel/rate-plans', [
                'room_type_id' => $types[$type], 'code' => $code, 'name' => $name, 'board' => $board, 'currency' => 'KES',
                'weekday_rate_minor' => $weekday, 'weekend_rate_minor' => $weekend, 'tax_inclusive' => true,
            ])['data']['id'];
        }

        $today = now()->format('Y-m-d');
        $reservations = [];

        foreach ([
            ['Grace Wambui', '0722 555 101', 'STD', 'STD-BB', 0, 3, 2],
            ['James Mwangi', '0733 555 102', 'DLX', 'DLX-BB', 0, 2, 2],
            ['Fatuma Ali', '0711 555 103', 'STD', 'STD-RO', 1, 4, 1],
            ['Peter Kiprono', '0700 555 104', 'DLX', 'DLX-BB', 3, 6, 2],
            ['Lucy Njeri', '0722 555 105', 'STD', 'STD-BB', 7, 10, 2],
        ] as [$guest, $phone, $type, $plan, $from, $to, $adults]) {
            $reservations[] = $api->post('hotel/reservations', [
                'branch_id' => $branch,
                'guest' => ['name' => $guest, 'phone' => $phone, 'nationality' => 'Kenyan', 'id_type' => 'national_id', 'id_number' => (string) (31000000 + count($reservations) * 1111)],
                'source' => 'phone',
                'stays' => [[
                    'room_type_id' => $types[$type], 'rate_plan_id' => $plans[$plan],
                    'arrival_on' => now()->addDays($from)->format('Y-m-d'), 'departure_on' => now()->addDays($to)->format('Y-m-d'), 'adults' => $adults,
                ]],
            ])['data'];
        }

        $stay = $reservations[0]['stays'][0]['id'];
        $api->post("hotel/stays/{$stay}/check-in", ['room_id' => $rooms[101], 'keys_issued' => 2]);
    }
}
