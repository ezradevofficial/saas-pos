<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** A bank account, a posted customer invoice and supplier bill, a receipt allocated to the invoice, and an operating budget (ACC-09, ACC-10, ACC-16). */
class AccountingMoreSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api->as('accountant');
        $base = "companies/{$demo->ids['company']}/accounting";

        if ($api->rows("{$base}/documents") !== []) {
            return;
        }

        $accounts = collect($api->rows("{$base}/accounts"));

        if ($accounts->isEmpty()) {
            return;
        }

        $code = fn (string $code) => $accounts->firstWhere('code', $code)['id'];
        $parties = collect($api->rows('parties'));
        $customer = $parties->first(fn ($party) => in_array('customer', $party['roles'] ?? [], true));
        $supplier = $parties->first(fn ($party) => in_array('supplier', $party['roles'] ?? [], true));
        $currency = $demo->ids['currency'];

        $bank = collect($api->rows("{$base}/bank-accounts"))->first()
            ?? $api->post("{$base}/bank-accounts", ['name' => 'Equity Bank current account', 'kind' => 'bank', 'account_id' => $code('1120'), 'currency' => $currency, 'bank_name' => 'Equity Bank', 'account_number' => '0170192837465'])['data'];

        $invoice = null;

        if ($customer !== null) {
            $invoice = $api->post("{$base}/documents", [
                'kind' => 'customer_invoice', 'party_id' => $customer['id'], 'issue_date' => now()->subDays(12)->format('Y-m-d'), 'currency' => $currency,
                'lines' => [
                    ['description' => 'Wholesale supply, October', 'account_id' => $code('4100'), 'qty' => '4', 'unit_price' => '12500'],
                    ['description' => 'Delivery', 'account_id' => $code('4100'), 'qty' => '1', 'unit_price' => '3500'],
                ],
            ])['data'];
            $api->post("accounting/documents/{$invoice['id']}/post");
        }

        if ($supplier !== null) {
            $bill = $api->post("{$base}/documents", [
                'kind' => 'supplier_invoice', 'party_id' => $supplier['id'], 'issue_date' => now()->subDays(6)->format('Y-m-d'), 'currency' => $currency,
                'supplier_reference' => 'SUP-INV-4471',
                'lines' => [['description' => 'Cleaning and packaging supplies', 'account_id' => $code('6600'), 'qty' => '1', 'unit_price' => '24000']],
            ])['data'];
            $api->post("accounting/documents/{$bill['id']}/post");
        }

        if ($customer !== null && $invoice !== null) {
            $api->post("{$base}/payments", [
                'kind' => 'receipt', 'party_id' => $customer['id'], 'bank_account_id' => $bank['id'], 'date' => now()->subDays(3)->format('Y-m-d'),
                'amount' => '30000', 'reference' => 'MPESA-QJK82H1', 'allocations' => [['document_id' => $invoice['id'], 'amount' => '30000']],
            ]);
        }

        $budget = $api->post("{$base}/budgets", ['name' => 'Operating budget', 'fiscal_year' => (int) now()->format('Y')])['data'];
        $cells = [];

        foreach (range(1, 12) as $month) {
            $cells[] = ['account_id' => $code('4100'), 'month' => $month, 'amount' => '480000'];
            $cells[] = ['account_id' => $code('6200'), 'month' => $month, 'amount' => '180000'];
            $cells[] = ['account_id' => $code('6100'), 'month' => $month, 'amount' => '45000'];
        }

        $api->put("accounting/budgets/{$budget['id']}/cells", ['cells' => $cells]);
    }
}
