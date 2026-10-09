<?php

namespace Modules\POS\Http\Resources;

use App\Core\Currency\Money;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;

/**
 * POS-12: a sale for the back office. Amounts are `{amount_minor,
 * currency}` (ADR 003); the detail (`detail()`) adds lines, payments, the
 * void and refunds.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $money = fn ($minor, ?string $currency = null) => $minor === null ? null : Money::ofMinor((string) $minor, $currency ?? $this->currency);
        $named = fn ($model) => $model === null ? null : ['id' => $model->id, 'name' => $model->name];

        $data = [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'sold_at' => $this->sold_at->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            'offline' => $this->offline,
            'company' => $named($this->company),
            'branch' => $named($this->branch),
            'location' => $named($this->location),
            'device' => $named($this->device),
            'shift_id' => $this->shift_id,
            'cashier' => $named($this->cashier),
            'customer' => $named($this->customer),
            'currency' => $this->currency,
            'subtotal' => $money($this->subtotal_minor),
            'discount' => $money($this->discount_minor),
            'tax' => $money($this->tax_minor),
            'total' => $money($this->total_minor),
            'paid' => $money($this->paid_minor),
            'change' => $money($this->change_minor, $this->change_currency),
            'rounding' => $money($this->rounding_minor),
            'base_total' => $money($this->base_total_minor, $this->base_currency),
            'base_tax' => $money($this->base_tax_minor, $this->base_currency),
            'fx' => $this->fx,
            'flags' => $this->flags,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'voided_at' => $this->voided_at?->toIso8601String(),
            // Phase 4 Task 6: what was tendered, per method type and currency (when payments are loaded).
            'tenders' => $this->whenLoaded('payments', fn () => $this->payments
                ->groupBy(fn (SalePayment $payment) => $payment->method_type.'|'.$payment->currency)
                ->map(fn ($group) => [
                    'method_type' => $group->first()->method_type,
                    'amount' => Money::ofMinor((string) $group->reduce(fn ($sum, SalePayment $p) => $sum->plus(BigInteger::of((string) $p->amount_minor)), BigInteger::zero()), $group->first()->currency),
                ])->values()->all()),
        ];

        if (! $this->detail) {
            return $data;
        }

        return [
            ...$data,
            'lines' => $this->lines->map(fn (SaleLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item' => ['id' => $line->item_id, 'name' => $line->item_name],
                'uom_id' => $line->uom_id,
                'qty' => $line->qty,
                'unit_price' => $money($line->unit_price_minor),
                'list_price' => $money($line->list_price_minor),
                'price_list_id' => $line->price_list_id,
                'tax_inclusive' => $line->tax_inclusive,
                'discount' => $money($line->discount_minor),
                'tax_code_id' => $line->tax_code_id,
                'tax_rate' => $line->tax_rate,
                'net' => $money($line->net_minor),
                'tax' => $money($line->tax_minor),
                'total' => $money($line->total_minor),
                'price_override_by' => $line->price_override_by,
                'discount_override_by' => $line->discount_override_by,
                'refunded_qty' => $line->refunded_qty,
            ])->all(),
            'payments' => $this->payments->map(fn (SalePayment $payment) => [
                'id' => $payment->id,
                'payment_method_id' => $payment->payment_method_id,
                'method_name' => $payment->method?->name,
                'method_type' => $payment->method_type,
                'amount' => $money($payment->amount_minor, $payment->currency),
                'amount_in_sale' => $money($payment->amount_in_sale_minor),
                'fx' => $payment->fx,
                'provider_reference' => $payment->provider_reference,
                'status' => $payment->status,
            ])->all(),
            'void' => $this->voidRecord === null ? null : [
                'id' => $this->voidRecord->id,
                'status' => $this->voidRecord->status,
                'voided_by' => $this->voidRecord->voided_by,
                'approved_by' => $this->voidRecord->approved_by,
                'override_verified' => $this->voidRecord->override_verified,
                'reason' => $this->voidRecord->reason,
                'voided_at' => $this->voidRecord->voided_at->toIso8601String(),
            ],
            'refunds' => $this->refunds->map(fn ($refund) => [
                'id' => $refund->id,
                'receipt_number' => $refund->receipt_number,
                'status' => $refund->status,
                'total' => $money($refund->total_minor),
                'tax' => $money($refund->tax_minor),
                'cashier_id' => $refund->cashier_id,
                'approved_by' => $refund->approved_by,
                'override_verified' => $refund->override_verified,
                'reason' => $refund->reason,
                'refunded_at' => $refund->refunded_at->toIso8601String(),
            ])->all(),
        ];
    }
}
