<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** The Kenyan chart of accounts from the country template (ACC-01), the default posting rules (ACC-24), and posted manual journals. */
class AccountingSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $base = "companies/{$demo->ids['company']}/accounting";

        if ($api->rows("{$base}/accounts") !== []) {
            return;
        }

        $api->post("{$base}/chart");
        $api->post("{$base}/posting-rules/defaults");

        $accounts = collect($api->rows("{$base}/accounts"));
        $code = fn (string $code) => $accounts->firstWhere('code', $code)['id'];

        [$bank, $cash, $capital, $rent, $salaries, $revenue] = array_map($code, ['1120', '1110', '3100', '6200', '6100', '4100']);

        $journals = [];

        foreach ([
            [now()->startOfMonth()->format('Y-m-d'), 'Owner capital paid into the bank', 'CAP-001', [[$bank, '2500000', null], [$capital, null, '2500000']]],
            [now()->startOfMonth()->addDays(2)->format('Y-m-d'), 'Shop rent for the month', 'RENT-OCT', [[$rent, '180000', null], [$bank, null, '180000']]],
            [now()->startOfMonth()->addDays(3)->format('Y-m-d'), 'Casual labour paid in cash', 'CASH-014', [[$salaries, '45000', null], [$cash, null, '45000']]],
            [now()->startOfMonth()->addDays(5)->format('Y-m-d'), 'Wholesale sale paid by bank transfer', 'INV-0091', [[$bank, '320000', null], [$revenue, null, '320000']]],
        ] as [$date, $memo, $reference, $lines]) {
            $journal = $api->post("{$base}/journals", [
                'date' => $date,
                'memo' => $memo,
                'reference' => $reference,
                'lines' => array_map(fn ($l) => ['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], $lines),
            ])['data'];
            $api->post("accounting/journals/{$journal['id']}/submit");
            $journals[] = $journal['id'];
        }

        // Approval posts the journal (ACC-05).
        $demo->approveAll('accountant');

        // One more waits for approval, so the inbox and the journal list show it.
        $waiting = $api->post("{$base}/journals", [
            'date' => now()->format('Y-m-d'),
            'memo' => 'Generator service and spare parts',
            'reference' => 'BILL-2207',
            'lines' => [['account_id' => $code('6600'), 'debit' => '86000'], ['account_id' => $code('2120'), 'credit' => '86000']],
        ])['data'];
        $api->post("accounting/journals/{$waiting['id']}/submit");
    }
}
