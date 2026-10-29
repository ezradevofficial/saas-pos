<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Throwable;

/**
 * What the procurement screens need beyond ProcurementSection (user manual, batch B): the
 * first purchase order sent and received, an approved requisition, a request for quotation
 * with two quotes, a published tender, a matched supplier invoice and a landed cost.
 * Every block checks what exists first, so a second run adds nothing.
 */
class ProcurementMoreSection
{
    use WaitsForApproval;

    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $branch = array_values($demo->ids['branches'])[0];
        $location = $demo->ids['locations'][0];
        $items = $demo->ids['items'];
        $suppliers = collect($api->rows('parties', ['role' => 'supplier']))->pluck('id')->take(3)->values()->all();

        // The first purchase order: approved, sent to the supplier and received.
        $order = collect($api->rows('procurement/orders'))->sortBy('created_at')->first();

        if ($order !== null && $order['status'] === 'pending') {
            $this->approveUntil($demo, 'branch_manager', fn () => $api->get("procurement/orders/{$order['id']}")['data']['status'] === 'approved');
        }

        if ($order !== null && $api->get("procurement/orders/{$order['id']}")['data']['status'] === 'approved') {
            $api->post("procurement/orders/{$order['id']}/send");
            $full = $api->get("procurement/orders/{$order['id']}")['data'];
            $receipt = $api->post('procurement/receipts', [
                'order_id' => $full['id'], 'location_id' => $location, 'delivery_note' => 'DN-20418',
                'lines' => array_map(fn ($line) => ['order_line_id' => $line['id'], 'received_qty' => $line['qty']], $full['lines']),
            ])['data'];
            $api->post("procurement/receipts/{$receipt['id']}/post");
        }

        // A second order waits for approval, so the approvals inbox has an item.
        if (count($api->rows('procurement/orders')) < 2 && isset($suppliers[1])) {
            $second = $api->post('procurement/orders', [
                'branch_id' => $branch, 'party_id' => $suppliers[1], 'location_id' => $location,
                'lines' => [['item_id' => $this->sample($demo, '02'), 'qty' => '300', 'unit_price_minor' => '5200']],
            ])['data'];
            $api->post("procurement/orders/{$second['id']}/submit");
        }

        $this->requisition($demo, $branch, $location, $items);
        $this->rfq($demo, $branch, $items, $suppliers);
        $this->tender($demo, $branch, $items, $suppliers);
        $this->invoiceAndLandedCost($demo, $branch, $suppliers);
    }

    private function requisition(DemoData $demo, string $branch, string $location, array $items): void
    {
        $api = $demo->api;

        if ($api->rows('procurement/requisitions') !== []) {
            return;
        }

        $requisition = $api->post('procurement/requisitions', [
            'branch_id' => $branch, 'location_id' => $location, 'purpose' => 'Restock flour, sugar and cooking oil for the main outlet',
            'needed_by' => now()->addDays(14)->toDateString(),
            'lines' => [
                ['item_id' => $this->sample($demo, '07'), 'qty' => '120', 'estimated_unit_price_minor' => '11000'],
                ['item_id' => $this->sample($demo, '06'), 'qty' => '80', 'estimated_unit_price_minor' => '15000'],
                ['item_id' => $this->sample($demo, '08'), 'qty' => '60', 'estimated_unit_price_minor' => '24000'],
            ],
        ])['data'];
        $api->post("procurement/requisitions/{$requisition['id']}/submit");
        $this->approveUntil($demo, 'branch_manager', fn () => $api->get("procurement/requisitions/{$requisition['id']}")['data']['status'] === 'approved');
    }

    private function rfq(DemoData $demo, string $branch, array $items, array $suppliers): void
    {
        $api = $demo->api;

        if ($api->rows('procurement/rfqs') !== [] || count($suppliers) < 3) {
            return;
        }

        $rfq = $api->post('procurement/rfqs', [
            'branch_id' => $branch, 'title' => 'Rice, tea leaves and bar soap for March', 'deadline_at' => now()->addDays(7)->toIso8601String(),
            'supplier_ids' => $suppliers,
            'lines' => [['item_id' => $this->sample($demo, '05'), 'qty' => '200'], ['item_id' => $this->sample($demo, '04'), 'qty' => '100'], ['item_id' => $this->sample($demo, '09'), 'qty' => '150']],
        ])['data'];
        $api->post("procurement/rfqs/{$rfq['id']}/issue");
        $rfq = $api->get("procurement/rfqs/{$rfq['id']}")['data'];
        $currency = $demo->ids['currency'];

        foreach ([[0, ['14200', '9800', '4300']], [1, ['13800', '10400', '4500']]] as [$who, $prices]) {
            $api->post('procurement/quotes', [
                'rfq_id' => $rfq['id'], 'party_id' => $suppliers[$who], 'reference' => 'Q-'.(310 + $who), 'quote_date' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(), 'currency' => $currency,
                'lines' => collect($rfq['lines'])->values()->map(fn ($line, $i) => ['rfq_line_id' => $line['id'], 'unit_price_minor' => $prices[$i], 'lead_time_days' => 3 + $who * 2])->all(),
            ]);
        }
    }

    private function tender(DemoData $demo, string $branch, array $items, array $suppliers): void
    {
        $api = $demo->api;

        if ($suppliers === []) {
            return;
        }

        $tender = $api->rows('procurement/tenders')[0] ?? null;

        if ($tender !== null) {
            $this->bidders($demo, $tender['id'], $suppliers);

            return;
        }

        $tender = $api->post('procurement/tenders', [
            'branch_id' => $branch, 'title' => 'Annual supply of packed foodstuffs', 'kind' => 'invited',
            'deadline_at' => now()->addDays(10)->toIso8601String(), 'opening_at' => now()->addDays(11)->toIso8601String(),
            'technical_weight' => 40, 'pass_mark' => '60', 'currency' => $demo->ids['currency'],
            'lines' => [['item_id' => $this->sample($demo, '05'), 'qty' => '2000'], ['item_id' => $this->sample($demo, '07'), 'qty' => '3000']],
            'criteria' => [['name' => 'Delivery within 3 days', 'weight' => 40, 'max_score' => 10], ['name' => 'Quality certificates', 'weight' => 35, 'max_score' => 10], ['name' => 'References from similar buyers', 'weight' => 25, 'max_score' => 10]],
            'openers' => [$demo->userId('owner'), $demo->userId('branch_manager')],
            'evaluators' => [$demo->userId('branch_manager')],
        ])['data'];
        $api->post("procurement/tenders/{$tender['id']}/publish");
        $this->bidders($demo, $tender['id'], $suppliers);
    }

    /** Two invited bidders; the link goes to the supplier's email, which the mail log keeps and nobody reads. */
    private function bidders(DemoData $demo, string $tender, array $suppliers): void
    {
        $api = $demo->api;

        if (($api->get("procurement/tenders/{$tender}")['data']['bidders'] ?? []) !== []) {
            return;
        }

        foreach (array_slice($suppliers, 0, 2) as $n => $party) {
            $api->post("procurement/tenders/{$tender}/bidders", ['party_id' => $party, 'email' => 'tenders'.($n + 1).'@supplier.example.com']);
        }
    }

    private function invoiceAndLandedCost(DemoData $demo, string $branch, array $suppliers): void
    {
        $api = $demo->api;
        $order = collect($api->rows('procurement/orders'))->first(fn ($row) => in_array($row['status'], ['received', 'partially_received', 'ordered'], true));

        if ($order !== null && $api->rows('procurement/invoices') === []) {
            try {
                $full = $api->get("procurement/orders/{$order['id']}")['data'];
                $invoice = $api->post('procurement/invoices', [
                    'order_id' => $full['id'], 'supplier_reference' => 'INV-77120', 'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'lines' => array_map(fn ($line) => ['order_line_id' => $line['id'], 'qty' => $line['qty']], $full['lines']),
                ])['data'];

                if (($invoice['status'] ?? '') === 'matched') {
                    $api->post("procurement/invoices/{$invoice['id']}/approve");
                }
            } catch (Throwable) {
                // The invoice screens still show their empty state; the rest of the module is filled.
            }
        }

        $receipt = collect($api->rows('procurement/receipts'))->firstWhere('status', 'posted');

        if ($receipt !== null && $api->rows('procurement/landed-costs') === []) {
            $cost = $api->post('procurement/landed-costs', [
                'branch_id' => $branch, 'cost_date' => now()->toDateString(), 'method' => 'value', 'description' => 'Freight and clearing, Nairobi depot',
                'receipt_ids' => [$receipt['id']],
                'charges' => [
                    ['description' => 'Road freight', 'party_id' => $suppliers[2] ?? null, 'bill_reference' => 'FRT-5521', 'currency' => $demo->ids['currency'], 'amount_minor' => '1800000'],
                    ['description' => 'Insurance', 'currency' => $demo->ids['currency'], 'amount_minor' => '250000'],
                ],
            ])['data'];

            try {
                $api->post("procurement/landed-costs/{$cost['id']}/post");
            } catch (Throwable) {
                // Left as a draft: its page still shows the allocation preview.
            }
        }
    }
}
