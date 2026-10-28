<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** Medicines with their class, lots received with batch numbers and expiry dates (one near expiry), and a prescription (PHA-01 to PHA-12). */
class PharmacySection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $location = $demo->ids['locations'][0];

        if ($api->rows('pharmacy/lots') !== []) {
            return;
        }

        $medicines = [
            ['PARA500', 'Paracetamol 500 mg tablets (strip of 10)', 5000, 'otc', 2500],
            ['ORS', 'Oral rehydration salts sachet', 3000, 'otc', 1500],
            ['AMOX500', 'Amoxicillin 500 mg capsules (strip of 10)', 12000, 'prescription_only', 7000],
            ['COART', 'Artemether-lumefantrine tablets (pack of 24)', 45000, 'prescription_only', 28000],
            ['DIAZ5', 'Diazepam 5 mg tablets (strip of 10)', 9000, 'controlled', 4500],
        ];
        $lines = [];

        foreach ($medicines as $n => [$code, $name, $price, $class, $cost]) {
            $item = $demo->item($code, $name, 'stock', $price);
            $api->put("pharmacy/items/{$item['id']}/profile", ['class' => $class, 'product_kind' => 'human', 'batch_tracked' => true, 'restricted_sale' => $class !== 'otc']);
            $lines[] = [
                'item_id' => $item['id'], 'qty' => (string) (80 + $n * 20), 'batch_number' => sprintf('B%s-%03d', strtoupper(substr($code, 0, 3)), 101 + $n),
                'expires_on' => now()->addMonths($n === 1 ? 2 : 14 + $n)->format('Y-m-d'), 'manufactured_on' => now()->subMonths(6)->format('Y-m-d'),
                'unit_cost_minor' => (string) $cost, 'currency' => 'KES',
            ];
            // A second, older lot of the same medicine, so first-expiry-first-out has a choice.
            $lines[] = [
                'item_id' => $item['id'], 'qty' => '40', 'batch_number' => sprintf('B%s-%03d', strtoupper(substr($code, 0, 3)), 90 + $n),
                'expires_on' => now()->addMonths(5 + $n)->format('Y-m-d'), 'manufactured_on' => now()->subMonths(18)->format('Y-m-d'),
                'unit_cost_minor' => (string) $cost, 'currency' => 'KES',
            ];
        }

        $supplier = $demo->ids['suppliers'][1] ?? null;
        $api->post('pharmacy/receipts', array_filter([
            'location_id' => $location, 'supplier_id' => $supplier, 'reference' => 'DN-77120', 'lines' => $lines,
        ]));

        $api->post('pharmacy/prescriptions', [
            'location_id' => $location, 'prescriber_name' => 'Dr. Jane Muthoni', 'prescriber_licence' => 'KMPDC-A12345', 'facility' => 'Westlands Health Centre',
            'patient_name' => 'Samuel Otieno', 'patient_phone' => '0722 600 111', 'patient_birth_date' => '1984-03-12', 'prescribed_on' => now()->subDay()->format('Y-m-d'),
            'notes' => 'Amoxicillin 500 mg, one capsule three times daily for five days',
        ]);
    }
}
