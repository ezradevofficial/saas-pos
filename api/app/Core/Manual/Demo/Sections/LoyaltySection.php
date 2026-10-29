<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** The points programme, three promotions (one with coupon codes), gift cards and vouchers, and credit accounts with a balance (LOY-01 to LOY-24). */
class LoyaltySection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];
        $currency = $demo->ids['currency'];

        if ($api->rows('loyalty/promotions') !== []) {
            return;
        }

        $api->put("companies/{$company}/loyalty-programme", [
            'active' => true, 'earn_points' => 1, 'earn_spend_minor' => '10000', 'point_value_minor' => '100', 'min_redeem_points' => '100',
            'expiry_months' => 12, 'expiry_notice_days' => 30, 'credit_warning_percent' => 80, 'gift_card_low_minor' => '50000',
        ]);

        $items = array_slice($demo->ids['items'], 0, 3);

        $api->post('loyalty/promotions', [
            'company_id' => $company, 'name' => 'Weekend 10 percent off', 'type' => 'percent', 'percent' => '10', 'priority' => 10, 'status' => 'active',
            'targets' => ['items' => [$items[0], $items[1]]], 'schedule' => ['days' => [6, 7]],
        ]);
        $api->post('loyalty/promotions', [
            'company_id' => $company, 'name' => 'Buy 2 get 1 free', 'type' => 'buy_x_get_y', 'percent' => '100', 'buy_qty' => 2, 'get_qty' => 1, 'priority' => 5, 'status' => 'draft',
            'targets' => ['items' => [$items[2]]],
        ]);
        $coupon = $api->post('loyalty/promotions', [
            'company_id' => $company, 'name' => 'Welcome back KES 200 off', 'type' => 'amount_off', 'amount_minor' => '20000', 'currency' => $currency, 'min_spend_minor' => '100000',
            'requires_coupon' => true, 'priority' => 1, 'status' => 'active',
        ])['data'];
        $api->post("loyalty/promotions/{$coupon['id']}/coupons", ['codes' => ['WELCOME200', 'KARIBU200'], 'max_uses' => 100]);

        $api->post('loyalty/gift-cards', ['company_id' => $company, 'kind' => 'gift_card', 'currency' => $currency, 'amount_minor' => '200000', 'count' => 3, 'active' => true]);
        $api->post('loyalty/gift-cards', ['company_id' => $company, 'kind' => 'voucher', 'currency' => $currency, 'amount_minor' => '50000', 'count' => 2, 'expires_on' => now()->addMonths(3)->format('Y-m-d'), 'active' => true]);
        $api->post('loyalty/gift-cards', ['company_id' => $company, 'kind' => 'gift_card', 'currency' => $currency, 'amount_minor' => '500000', 'count' => 1]);

        $names = [['Baraka Cafe', 3200000, 1200000], ['Jirani Minimart', 1850000, 0], ['Safari Guest House', 0, 0]];

        foreach ($names as [$name, $charge, $paid]) {
            $party = collect($api->rows('parties', ['search' => $name]))->firstWhere('name', $name)['id']
                ?? $api->post('parties', ['kind' => 'organisation', 'name' => $name, 'roles' => ['customer'], 'addresses' => [['line1' => 'Nairobi', 'city' => 'Nairobi', 'country' => 'KE']]])['data']['id'];
            $account = $api->post('loyalty/accounts', ['company_id' => $company, 'party_id' => $party])['data'];

            if ($charge > 0) {
                $api->post("loyalty/accounts/{$account['id']}/charges", ['amount_minor' => (string) $charge, 'reference' => 'INV-2026-0412', 'note' => 'Stock on account']);
            }

            if ($paid > 0) {
                $api->post("loyalty/accounts/{$account['id']}/payments", ['amount_minor' => (string) $paid, 'reference' => 'MPESA-QJH7K2', 'note' => 'Part payment']);
            }
        }
    }
}
