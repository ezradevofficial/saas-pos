<?php

namespace Modules\Ecommerce\Orders;

/** ECOM-09: a priced cart as the API shows it: amounts as digit strings in minor units, the currency with them. */
final class QuoteView
{
    /** @param array<string, mixed> $quote Pricing::quote() */
    public static function present(array $quote): array
    {
        $minor = fn (int|string $value) => (string) $value;

        return [
            'currency' => $quote['currency'],
            'tax_inclusive' => $quote['tax_inclusive'],
            'fulfilment' => $quote['fulfilment'],
            'zone_id' => $quote['zone_id'],
            'pickup_location_id' => $quote['pickup_location_id'],
            'lines' => array_map(fn (array $line) => [
                'line_no' => $line['line_no'],
                'product_id' => $line['product_id'],
                'item_id' => $line['item_id'],
                'name' => $line['name'],
                'qty' => $line['qty'],
                'unit_price_minor' => $minor($line['unit_price_minor']),
                'gross_minor' => $minor($line['gross_minor']),
                'discount_minor' => $minor($line['discount_minor']),
                'net_minor' => $minor($line['net_minor']),
                'tax_minor' => $minor($line['tax_minor']),
                'total_minor' => $minor($line['total_minor']),
                'tax_rate' => $line['tax_rate'],
                'promotion' => $line['promotion'],
            ], $quote['lines']),
            'totals' => array_map($minor, $quote['totals']),
            // The fee as the customer pays it (with its tax, inclusive or not).
            'delivery_minor' => $minor($quote['totals']['total_minor'] - array_sum(array_column($quote['lines'], 'total_minor'))),
            'coupon' => $quote['coupon'],
            'promotions' => array_map(fn (array $p) => ['promotion_id' => $p['promotion_id'] ?? null, 'discount_minor' => $minor($p['discount_minor'] ?? '0'), 'coupon' => $p['coupon'] ?? null], $quote['promotions']),
            'loyalty' => [
                'points' => $quote['loyalty']['points'],
                'points_minor' => $minor($quote['loyalty']['points_minor']),
                'gift_card_minor' => $minor($quote['loyalty']['gift_card_minor']),
            ],
            'loyalty_paid_minor' => $minor($quote['loyalty_paid_minor']),
            'due_minor' => $minor($quote['due_minor']),
            'problems' => $quote['problems'],
        ];
    }
}
