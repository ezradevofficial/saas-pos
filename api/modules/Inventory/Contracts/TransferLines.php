<?php

namespace Modules\Inventory\Contracts;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Modules\Inventory\Models\Transfer;

/**
 * INV-27 (Pharmacy, PHA-13): what a received transfer moved, as plain data
 * for modules listening to TransferReceived, so they never read Inventory's
 * tables (architecture rule 1). Read in the event's tenant. Quantities are
 * decimal strings in each item's base unit.
 *
 * @phpstan-type Lines array{transfer_id: string, company_id: string, from_location_id: string, to_location_id: string, received_at: ?string, reference: string, lines: list<array{line_id: string, item_id: string, qty_sent: string, qty_received: string}>}
 */
final class TransferLines
{
    /** @return Lines|null null when the transfer is not stored */
    public static function forTransfer(string $transferId): ?array
    {
        $transfer = Transfer::query()->with('lines')->find($transferId);

        if ($transfer === null) {
            return null;
        }

        return [
            'transfer_id' => $transfer->id,
            'company_id' => $transfer->company_id,
            'from_location_id' => $transfer->from_location_id,
            'to_location_id' => $transfer->to_location_id,
            'received_at' => $transfer->received_at?->toIso8601String(),
            'reference' => (string) $transfer->number,
            'lines' => $transfer->lines->map(function ($line) {
                $sent = BigDecimal::of((string) $line->qty_base);
                $factor = BigDecimal::of((string) $line->qty)->isZero() ? BigDecimal::one() : $sent->dividedBy((string) $line->qty, 12, RoundingMode::HalfUp);
                // `qty_received` is in the line's unit; the base quantity is what Pharmacy moves.
                $received = $line->qty_received === null ? $sent : BigDecimal::of((string) $line->qty_received)->multipliedBy($factor)->toScale(6, RoundingMode::HalfUp);

                return ['line_id' => (string) $line->id, 'item_id' => (string) $line->item_id, 'qty_sent' => (string) $sent->toScale(6, RoundingMode::HalfUp), 'qty_received' => (string) $received];
            })->values()->all(),
        ];
    }
}
