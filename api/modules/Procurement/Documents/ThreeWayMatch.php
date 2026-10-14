<?php

namespace Modules\Procurement\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Modules\Procurement\Models\InvoiceLine;

/**
 * PRC-15: the three-way match of one invoice line against its PO line
 * (the ordered unit price) and what was received and not yet invoiced
 * (posted GRNs' accepted quantity less approved invoices'), within the
 * company's tolerances (PRC-01). Pure arithmetic, exact decimals.
 *
 * - `not_received`: nothing of the line was accepted yet;
 * - `qty_exceeds_received`: the quantity is above what is left to invoice
 *   by more than the quantity tolerance;
 * - `price_variance`: the unit price differs from the PO's by more than
 *   the price tolerance (either way);
 * - `matched` otherwise.
 *
 * The price variance is round_half_up((invoiced − ordered price) × qty) in
 * minor units of the invoice currency; the quantity variance is how much
 * the quantity is above what is left (0 when not).
 */
final class ThreeWayMatch
{
    /** @return array{status: string, price_variance_minor: int, qty_variance: string} */
    public static function line(
        string $qty,
        int $unitPriceMinor,
        int $orderedPriceMinor,
        string $acceptedQty,
        string $invoicedQty,
        string $priceTolerancePercent,
        string $qtyTolerancePercent,
    ): array {
        $qty = BigDecimal::of($qty);
        $left = BigDecimal::of($acceptedQty)->minus($invoicedQty);
        $priceVariance = (int) (string) BigDecimal::of($unitPriceMinor - $orderedPriceMinor)->multipliedBy($qty)->toScale(0, RoundingMode::HalfUp);
        $qtyVariance = $qty->isGreaterThan($left) ? $qty->minus($left->isNegative() ? BigDecimal::zero() : $left) : BigDecimal::zero();

        $allowedQty = $left->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($qtyTolerancePercent)->dividedBy(100, 8, RoundingMode::HalfUp)));
        $allowedPrice = BigDecimal::of($orderedPriceMinor)->multipliedBy($priceTolerancePercent)->dividedBy(100, 8, RoundingMode::HalfUp);
        $priceOff = BigDecimal::of(abs($unitPriceMinor - $orderedPriceMinor))->isGreaterThan($allowedPrice);

        $status = match (true) {
            ! BigDecimal::of($acceptedQty)->isPositive() => InvoiceLine::NOT_RECEIVED,
            $qty->isGreaterThan($allowedQty) => InvoiceLine::QTY_EXCEEDS_RECEIVED,
            $priceOff => InvoiceLine::PRICE_VARIANCE,
            default => InvoiceLine::MATCHED,
        };

        return ['status' => $status, 'price_variance_minor' => $priceVariance, 'qty_variance' => (string) $qtyVariance->toScale(6, RoundingMode::HalfUp)];
    }
}
