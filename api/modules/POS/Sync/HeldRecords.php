<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SaleVoid;

/**
 * POS-05, H2: what a void, refund or cash movement does when it is
 * applied, at upload (proven) or later in the back office (a held one
 * approved by someone holding the permission at its location), and what
 * rejecting a held one does. Only applying changes the sale, the refunded
 * quantities and the drawer, and raises events; every step is audited
 * naming who decided. Call inside a transaction.
 */
class HeldRecords
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly ShiftCash $cash,
        private readonly TenantContext $tenants,
    ) {}

    public function applyVoid(SaleVoid $void, ?User $decider = null): void
    {
        $sale = Sale::query()->whereKey($void->sale_id)->lockForUpdate()->firstOrFail();

        if ($sale->status !== Sale::COMPLETED) {
            throw new Rejection('sale_already_voided', 'sale_id');
        }

        if (Refund::query()->where('sale_id', $sale->id)->where('status', Records::APPLIED)->exists()) {
            throw new Rejection('sale_has_refunds', 'sale_id');
        }

        $sale->forceFill(['status' => Sale::VOIDED, 'voided_at' => $void->voided_at])->save();
        $void->forceFill(['status' => Records::APPLIED, ...$this->decided($decider)])->save();

        $this->auditor->record('pos.sale.void', $sale, ['status' => Sale::COMPLETED], [
            'status' => Sale::VOIDED,
            'void_id' => $void->id,
            'reason' => $void->reason,
            'approved_by' => $void->approved_by,
            'override_verified' => $void->override_verified,
            'decided_by' => $decider?->id,
        ], $this->actors($void->voided_by, $decider?->id ?? $void->approved_by, $decider === null ? $void->voided_at : null));
        $this->cash->recountIfClosed($sale->shift_id, 'void', $void->id);
        SaleVoided::dispatch($this->tenants->require(), $sale->id, $void->id);
    }

    public function applyRefund(Refund $refund, ?User $decider = null): void
    {
        $sale = Sale::query()->whereKey($refund->sale_id)->lockForUpdate()->firstOrFail();

        if ($sale->status !== Sale::COMPLETED) {
            throw new Rejection('sale_already_voided', 'sale_id');
        }

        $lines = RefundLine::query()->where('refund_id', $refund->id)->get();

        foreach ($lines as $line) {
            $saleLine = SaleLine::query()->whereKey($line->sale_line_id)->lockForUpdate()->firstOrFail();
            $refunded = BigDecimal::of($saleLine->refunded_qty)->plus($line->qty);

            if ($refunded->isGreaterThan($saleLine->qty)) {
                throw new Rejection('refund_qty_exceeded', 'lines');
            }

            $saleLine->forceFill(['refunded_qty' => (string) $refunded])->save();
        }

        $refund->forceFill(['status' => Records::APPLIED, ...$this->decided($decider)])->save();

        $this->auditor->record('pos.sale.refund', $sale, null, [
            'refund_id' => $refund->id,
            'receipt_number' => $refund->receipt_number,
            'total' => Money::ofMinor((string) $refund->total_minor, $refund->currency),
            'lines' => $lines->map(fn (RefundLine $l) => ['sale_line_id' => $l->sale_line_id, 'qty' => $l->qty])->all(),
            'reason' => $refund->reason,
            'approved_by' => $refund->approved_by,
            'override_verified' => $refund->override_verified,
            'decided_by' => $decider?->id,
        ], $this->actors($refund->cashier_id, $decider?->id ?? $refund->approved_by, $decider === null ? $refund->refunded_at : null));
        $this->cash->recountIfClosed($refund->shift_id, 'refund', $refund->id);
        SaleRefunded::dispatch($this->tenants->require(), $sale->id, $refund->id);
    }

    public function applyMovement(CashMovement $movement, User $decider): void
    {
        $movement->forceFill(['status' => Records::APPLIED, ...$this->decided($decider)])->save();

        $this->auditor->record("pos.cash.{$movement->kind}", $movement, ['status' => Records::HELD], [
            'status' => Records::APPLIED,
            'amount_minor' => (string) $movement->amount_minor,
            'currency' => $movement->currency,
            'decided_by' => $decider->id,
        ], $this->actors($movement->user_id, $decider->id, null));
        $this->cash->recountIfClosed($movement->shift_id, 'cash_movement', $movement->id);
    }

    /** A held record turned down: it never counts; audited with the reason. */
    public function reject(SaleVoid|Refund|CashMovement $record, User $decider, string $reason): void
    {
        $record->forceFill(['status' => Records::REJECTED, ...$this->decided($decider)])->save();

        $action = match (true) {
            $record instanceof SaleVoid => 'pos.sale.void_reject',
            $record instanceof Refund => 'pos.sale.refund_reject',
            default => 'pos.cash.reject',
        };

        $this->auditor->record($action, $record, ['status' => Records::HELD], [
            'status' => Records::REJECTED,
            'reason' => $reason,
            'decided_by' => $decider->id,
        ], ['user_id' => $decider->id]);
    }

    /** @return array<string, mixed> */
    private function decided(?User $decider): array
    {
        return $decider === null ? [] : ['decided_by' => $decider->id, 'decided_at' => now()];
    }

    /** @return array<string, mixed> audit actors: who did it, who allowed it, and the till's time when it came from the till */
    private function actors(?string $userId, ?string $onBehalfOf, mixed $deviceTime): array
    {
        return array_filter(['user_id' => $userId, 'on_behalf_of_user_id' => $onBehalfOf, 'device_time' => $deviceTime], fn ($v) => $v !== null);
    }
}
