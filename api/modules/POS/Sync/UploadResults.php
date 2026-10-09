<?php

namespace Modules\POS\Sync;

use App\Core\Fiscal\FiscalQueue;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Models\Shift;

/**
 * POS-09: one result per uploaded record. A stored record answers the same
 * result however often it is sent (ADR 004: a resend is harmless and
 * looks like the first upload); a refused one carries the error.
 */
final class UploadResults
{
    public const STORED = 'stored';

    public const REJECTED = 'rejected';

    /** @return array<string, mixed> */
    public static function sale(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'status' => self::STORED,
            'receipt_number' => $sale->receipt_number,
            'flags' => $sale->flags,
            'received_at' => $sale->received_at->toIso8601String(),
            // POS-10: null when the company does not transmit; else the
            // authority's answer so far (`pending` until accepted; the
            // till asks GET pos/sales/{id}/fiscal later).
            'fiscal' => self::fiscal($sale),
        ];
    }

    private static function fiscal(Sale $sale): ?string
    {
        $queue = app(FiscalQueue::class);

        if (! $queue->transmits($sale->company_id)) {
            return null;
        }

        return $queue->statusFor(PosFiscalSource::KEY, 'sale', $sale->id)['status'] ?? 'pending';
    }

    /** @return array<string, mixed> */
    public static function shift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'status' => self::STORED,
            'shift_status' => $shift->status,
            'received_at' => $shift->received_at->toIso8601String(),
            'closed_received_at' => $shift->closed_received_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function voided(SaleVoid $void): array
    {
        return [
            'id' => $void->id,
            'status' => self::STORED,
            'sale_id' => $void->sale_id,
            'void_status' => $void->status,
            'flags' => $void->flags,
            'override_verified' => $void->override_verified,
            'received_at' => $void->received_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function refund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'status' => self::STORED,
            'sale_id' => $refund->sale_id,
            'receipt_number' => $refund->receipt_number,
            'refund_status' => $refund->status,
            'flags' => $refund->flags,
            'override_verified' => $refund->override_verified,
            'received_at' => $refund->received_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function cashMovement(CashMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'status' => self::STORED,
            'movement_status' => $movement->status,
            'flags' => $movement->flags,
            'override_verified' => $movement->override_verified,
            'received_at' => $movement->received_at->toIso8601String(),
        ];
    }

    /** @return array{id: string, status: string, error: array<string, mixed>} */
    public static function rejected(string $id, Rejection $rejection): array
    {
        return ['id' => $id, 'status' => self::REJECTED, 'error' => $rejection->toArray()];
    }
}
