<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Invoicing\BillingInvoicePdf;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Currency\CurrencyDecimals;
use Tests\TestCase;

/**
 * SUB-03: every printed line of a subscription invoice reads right:
 * quantity × unit price is the line's amount (before its share of the
 * discounts), whatever the included units, the months of an annual cycle,
 * a minimum charge, a bundle's extra outlets, overage days or a proration.
 */
class BillingInvoicePdfTest extends TestCase
{
    private function invoice(array $lines): BillingInvoice
    {
        return (new BillingInvoice)->forceFill([
            'number' => 'INV-2026-000001',
            'currency' => 'KES',
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'period_start' => '2026-11-01',
            'period_end' => '2027-11-01',
            'issued_at' => '2026-11-01 08:00:00',
            'due_at' => '2026-11-15 08:00:00',
            'lines' => $lines,
            'seller' => ['name' => 'Seller'],
            'customer' => ['name' => 'Buyer'],
        ]);
    }

    public function test_quantity_times_unit_price_is_the_line_amount_on_every_line(): void
    {
        $this->mock(CurrencyDecimals::class)->shouldReceive('for')->andReturn(2);
        $lines = [
            // A bundle for 3 outlets, 1 included: the bundle price plus 2 extra outlets, for 12 months.
            ['type' => 'bundle', 'key' => 'business', 'name' => null, 'unit' => 'outlet', 'quantity' => 3, 'billable' => 2, 'months' => 12, 'unit_amount' => 1000, 'amount' => (1000 + 2 * 300) * 12, 'discount_minor' => 1000, 'net_minor' => 18200],
            // 4 devices, 2 included: 2 charged, for 12 months.
            ['type' => 'module', 'key' => 'pos_device', 'name' => null, 'unit' => 'device', 'quantity' => 4, 'billable' => 2, 'months' => 12, 'unit_amount' => 500, 'amount' => 2 * 500 * 12],
            // A minimum charge above 3 × 100.
            ['type' => 'module', 'key' => 'hr', 'name' => null, 'unit' => 'employee', 'quantity' => 3, 'billable' => 3, 'months' => 1, 'unit_amount' => 100, 'amount' => 2000],
            // 5 users, none included, monthly.
            ['type' => 'module', 'key' => 'crm', 'name' => null, 'unit' => 'tenant', 'quantity' => 5, 'billable' => 5, 'months' => 1, 'unit_amount' => 700, 'amount' => 3500],
            ['type' => 'overage', 'key' => 'pos_device', 'name' => null, 'unit' => 'device', 'quantity' => 2, 'billable' => 2, 'months' => null, 'unit_amount' => 500, 'amount' => 333, 'days' => 10, 'period_days' => 30],
            ['type' => 'proration', 'key' => null, 'name' => null, 'quantity' => 12, 'period_days' => 30, 'amount' => 4000],
            ['type' => 'annual_discount', 'key' => null, 'name' => null, 'amount' => -1000, 'months' => 2],
        ];

        foreach (['en', 'fr'] as $locale) {
            $data = app(BillingInvoicePdf::class)->data($this->invoice($lines), $locale);
            $this->assertCount(6, $data['lines']);

            foreach ($data['lines'] as $i => $printed) {
                $this->assertSame(
                    $lines[$i]['amount'],
                    (int) $printed['qty'] * (int) $printed['unit_price']['minor'],
                    "line {$i} ({$lines[$i]['type']}) in {$locale}: {$printed['qty']} × {$printed['unit_price']['minor']}",
                );
                $this->assertSame($lines[$i]['amount'], (int) $printed['total']['minor'] + (int) $printed['discount']['minor']);
            }

            // The billable quantity is printed where it multiplies out, the included units named.
            $this->assertSame(['2', '6000'], [$data['lines'][1]['qty'], $data['lines'][1]['unit_price']['minor']]);
            $this->assertStringContainsString(__('billing.lines.included', ['count' => 2], $locale), $data['lines'][1]['item_name']);
            $this->assertSame(['5', '700'], [$data['lines'][3]['qty'], $data['lines'][3]['unit_price']['minor']]);
            // The bundle: one line, its outlets spelled out.
            $this->assertSame('1', $data['lines'][0]['qty']);
            $this->assertStringContainsString(__('billing.lines.bundle_outlets', ['outlets' => 3, 'included' => 1, 'extra' => 2], $locale), $data['lines'][0]['item_name']);
            $this->assertStringContainsString(__('billing.lines.minimum', [], $locale), $data['lines'][2]['item_name']);
        }
    }
}
