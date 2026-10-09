<?php

namespace Modules\POS\Http\Resources;

use App\Core\Currency\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Shift;
use Modules\POS\Models\ShiftBalance;

/**
 * POS-04, POS-12: a shift for the back office, with its cash per currency;
 * the detail (`detail()`) adds cash movements.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $named = fn ($model) => $model === null ? null : ['id' => $model->id, 'name' => $model->name];
        $money = fn ($minor, string $currency) => $minor === null ? null : Money::ofMinor((string) $minor, $currency);

        $data = [
            'id' => $this->id,
            'status' => $this->status,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'company' => $named($this->company),
            'branch' => $named($this->branch),
            'location' => $named($this->location),
            'device' => $named($this->device),
            'opened_by' => $named($this->opener),
            'closed_by' => $named($this->closer),
            'note' => $this->note,
            'sales_count' => $this->sales_count ?? null,
            'balances' => $this->balances->map(fn (ShiftBalance $balance) => [
                'currency' => $balance->currency,
                'opening' => $money($balance->opening_minor, $balance->currency),
                'counted' => $money($balance->counted_minor, $balance->currency),
                'expected' => $money($balance->expected_minor, $balance->currency),
                'variance' => $money($balance->variance_minor, $balance->currency),
            ])->all(),
        ];

        if (! $this->detail) {
            return $data;
        }

        return [
            ...$data,
            // POS-09, H4: sales and cash movements that reached the server after the close (the expected cash was recounted).
            'received_after_close' => $this->received_after_close ?? 0,
            'cash_movements' => $this->cashMovements->map(fn (CashMovement $movement) => [
                'id' => $movement->id,
                'kind' => $movement->kind,
                'status' => $movement->status,
                'flags' => $movement->flags,
                'user' => $named($movement->user),
                'approver' => $named($movement->approver),
                'amount' => $money($movement->amount_minor, $movement->currency),
                'reason' => $movement->reason,
                'user_id' => $movement->user_id,
                'approved_by' => $movement->approved_by,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ])->all(),
        ];
    }
}
