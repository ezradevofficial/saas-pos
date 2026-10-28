<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Carbon\Carbon;

/** Salon services, three stylists with weekly hours, chairs, clients, appointments through the day, and a commission rule and batch (SAL-01 to SAL-14). */
class SalonSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];
        $branch = array_values($demo->ids['branches'])[0];

        if ($api->rows('salon/staff') !== []) {
            return;
        }

        $staff = [];

        foreach ([['Njeri Mwangi', 'Senior stylist', 'c1'], ['Akinyi Ouma', 'Braiding specialist', 'c2'], ['Kamau Wafula', 'Barber', 'c3']] as [$name, $title, $color]) {
            $staff[] = $api->post('salon/staff', ['company_id' => $company, 'name' => $name, 'title' => $title, 'color' => $color, 'branch_id' => $branch])['data']['id'];
        }

        foreach ($staff as $id) {
            $hours = [];

            foreach ([1, 2, 3, 4, 5, 6] as $day) {
                $hours[] = ['branch_id' => $branch, 'weekday' => $day, 'kind' => 'work', 'start' => '08:00', 'end' => '18:00'];
                $hours[] = ['branch_id' => $branch, 'weekday' => $day, 'kind' => 'break', 'start' => '13:00', 'end' => '14:00'];
            }

            $api->put("salon/staff/{$id}/hours", ['hours' => $hours]);
        }

        $chairs = [];

        foreach (['Chair 1', 'Chair 2', 'Wash basin'] as $name) {
            $chairs[] = $api->post('salon/resources', ['branch_id' => $branch, 'name' => $name, 'kind' => 'chair'])['data']['id'];
        }

        $services = [];

        foreach ([['HAIRCUT', 'Haircut and style', 80000, 45, 'Hair'], ['BRAIDS', 'Box braids', 350000, 240, 'Hair'], ['BLOWDRY', 'Wash and blow-dry', 100000, 40, 'Hair'], ['MANI', 'Manicure', 120000, 50, 'Nails']] as [$code, $name, $price, $minutes, $category]) {
            $item = $demo->item($code, $name, 'service', $price);
            $services[$code] = $api->post('salon/services', [
                'company_id' => $company, 'item_id' => $item['id'], 'category' => $category, 'duration_minutes' => $minutes, 'online_bookable' => true, 'staff_ids' => $staff,
            ])['data']['id'];
        }

        $clients = [];

        foreach ([['Wangari Maathai', '0722 700 101'], ['Halima Yusuf', '0733 700 102'], ['Mercy Chebet', '0711 700 103']] as [$name, $phone]) {
            $clients[] = $api->post('salon/clients', ['company_id' => $company, 'name' => $name, 'phone' => $phone])['data']['party_id'];
        }

        $day = now('Africa/Nairobi')->next(Carbon::TUESDAY);
        $book = fn (int $client, string $service, int $stylist, string $time) => $api->post('salon/appointments', [
            'branch_id' => $branch, 'salon_client_id' => $clients[$client], 'starts_at' => $day->copy()->setTimeFromTimeString($time)->toIso8601String(),
            'source' => 'staff', 'lines' => [['service_id' => $services[$service], 'staff_id' => $staff[$stylist], 'resource_id' => $chairs[$stylist % 2]]],
        ])['data'];

        $book(0, 'HAIRCUT', 0, '09:00');
        $book(1, 'BRAIDS', 1, '08:30');
        $book(2, 'BLOWDRY', 0, '11:00');
        $book(0, 'MANI', 2, '15:00');

        $api->post('salon/commission-rules', [
            'company_id' => $company, 'applies_to' => 'service', 'kind' => 'percent', 'rate' => '30', 'effective_from' => now()->startOfYear()->format('Y-m-d'),
        ]);
    }
}
