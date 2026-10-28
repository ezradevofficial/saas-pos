<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Identity\Pin\ActorProofVerifier;
use App\Core\Manual\Demo\DemoData;
use App\Core\Sync\DeviceSecrets;
use Illuminate\Support\Str;

/**
 * A till paired at the main outlet (AUTH-06), one closed shift with cash, M-Pesa and card sales, and one
 * shift still open, all uploaded the way the app uploads them (NFR-04): idempotent, signed with the device secret.
 */
class PosSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $location = $demo->ids['locations'][0];

        if ($api->rows("locations/{$location}/devices") !== []) {
            return;
        }

        $name = 'Till 1';
        $device = $api->post("locations/{$location}/devices", ['name' => $name])['data'];
        $code = $api->post("devices/{$device['id']}/pairing-code")['code'];
        $paired = $api->send('POST', 'devices/pair', ['code' => $code, 'device_name' => $name], signedIn: false);
        $till = $api->bearer($paired['token']);
        $secret = ['kid' => $paired['device_secret_kid'], 'secret' => $paired['device_secret']];

        $cashier = collect($api->rows('users'))->firstWhere('email', 'demo-cashier@demo.example.com')['id'];
        $manager = collect($api->rows('users'))->firstWhere('email', 'demo-branch-manager@demo.example.com')['id'];
        $company = $demo->ids['company'];
        $vat = $demo->ids['vat'];
        $list = collect($api->rows("companies/{$company}/price-lists"))->firstWhere('is_default', true);
        $prices = $api->rows("price-lists/{$list['id']}/prices");
        $methods = collect($api->rows("companies/{$company}/payment-methods"))->keyBy('name');

        $allocated = $till->post('pos/number-ranges', ['document_type' => 'pos.receipt', 'next' => null])['data'][0];
        $prefix = explode('{', (string) $allocated['pattern'])[0];
        $seq = (int) $allocated['next'];

        $proof = function (string $userId) use ($device, $secret) {
            $fields = ['session_id' => (string) Str::uuid7(), 'user_id' => $userId, 'signed_in_at' => now()->subHours(9)->utc()->format('Y-m-d\TH:i:s.v\Z'), 'kid' => $secret['kid']];
            $message = ActorProofVerifier::message($device['id'], $fields['kid'], $fields['session_id'], $fields['user_id'], $fields['signed_in_at']);

            return $fields + ['signature' => DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($secret['secret']), true))];
        };

        $closedShift = (string) Str::uuid7();
        $till->post('pos/shifts', ['shifts' => [[
            'id' => $closedShift, 'opened_by_id' => $cashier, 'opened_at' => now()->subHours(8)->toIso8601String(),
            'opening_float' => [['currency' => 'KES', 'amount_minor' => '500000']], 'closing' => null,
        ]]]);

        $sale = function (string $shift, array $picks, string $method, int $minutesAgo) use ($till, $proof, $cashier, $list, $prices, $methods, $prefix, $vat, &$seq) {
            $lines = [];

            foreach ($picks as [$index, $qty]) {
                $price = $prices[$index];
                $total = (int) $price['amount_minor'] * $qty;
                $tax = $total - (int) round($total * 100 / (100 + (float) $vat['rate']));
                $lines[] = [
                    'id' => (string) Str::uuid7(), 'item_id' => $price['item_id'], 'uom_id' => $price['uom_id'], 'qty' => (string) $qty,
                    'unit_price_minor' => $price['amount_minor'], 'list_price_minor' => $price['amount_minor'], 'price_list_id' => $list['id'],
                    'tax_inclusive' => true, 'discount_minor' => '0', 'tax_code_id' => $vat['id'], 'tax_rate' => $vat['rate'], 'tax_minor' => (string) $tax,
                    'total_minor' => (string) $total, 'override' => null,
                ];
            }

            $total = (string) array_sum(array_column($lines, 'total_minor'));
            $taxTotal = (string) array_sum(array_column($lines, 'tax_minor'));
            $number = $seq++;

            return $till->post('pos/sales', ['sales' => [[
                'id' => (string) Str::uuid7(), 'shift_id' => $shift, 'cashier_id' => $cashier, 'actor_proof' => $proof($cashier), 'customer_id' => null,
                'receipt_seq' => $number, 'receipt_number' => $prefix.sprintf('%06d', $number),
                'sold_at' => now()->subMinutes($minutesAgo)->toIso8601String(), 'offline' => false, 'currency' => 'KES', 'price_list_id' => $list['id'],
                'lines' => $lines,
                'totals' => ['subtotal_minor' => $total, 'discount_minor' => '0', 'tax_minor' => $taxTotal, 'total_minor' => $total],
                'payments' => [[
                    'id' => (string) Str::uuid7(), 'payment_method_id' => $methods[$method]['id'], 'currency' => 'KES',
                    'amount_minor' => $total, 'amount_in_sale_minor' => $total, 'rate' => null, 'status' => 'confirmed',
                ]],
                'change' => ['currency' => 'KES', 'amount_minor' => '0', 'rate' => null],
            ]]]);
        };

        $sale($closedShift, [[0, 4], [1, 2]], 'Cash KES', 400);
        $sale($closedShift, [[2, 1], [3, 1], [8, 3]], 'M-Pesa', 330);
        $sale($closedShift, [[4, 2]], 'Cash KES', 260);
        $sale($closedShift, [[5, 1], [9, 2]], 'Card', 180);

        $till->post('pos/shifts', ['shifts' => [[
            'id' => $closedShift, 'opened_by_id' => $cashier, 'opened_at' => now()->subHours(8)->toIso8601String(),
            'opening_float' => [['currency' => 'KES', 'amount_minor' => '500000']],
            'closing' => ['closed_by_id' => $manager, 'closed_at' => now()->subHours(2)->toIso8601String(), 'counted' => [['currency' => 'KES', 'amount_minor' => '566000']], 'note' => 'End of day'],
        ]]]);

        $open = (string) Str::uuid7();
        $till->post('pos/shifts', ['shifts' => [[
            'id' => $open, 'opened_by_id' => $cashier, 'opened_at' => now()->subHour()->toIso8601String(),
            'opening_float' => [['currency' => 'KES', 'amount_minor' => '500000']], 'closing' => null,
        ]]]);
        $sale($open, [[6, 2], [7, 1]], 'Cash KES', 40);
        $sale($open, [[10, 5]], 'M-Pesa', 15);
    }
}
