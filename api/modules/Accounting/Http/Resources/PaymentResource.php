<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Payment;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'kind' => $this->kind,
            'number' => $this->number,
            'date' => $this->date->toDateString(),
            'party' => Shapes::named($this->party),
            'bank_account' => Shapes::named($this->bankAccount),
            'amount' => Shapes::money($this->amount_minor, $this->currency),
            'base_amount' => Shapes::money($this->base_amount_minor, $this->base_currency),
            'unallocated' => Shapes::money($this->unallocated_minor, $this->currency),
            'fx' => $this->fx?->jsonSerialize(),
            'reference' => $this->reference,
            'memo' => $this->memo,
            'journal_id' => $this->journal_id,
        ];

        if (! $this->detail) {
            return $data;
        }

        $allocations = Allocation::query()->where('payment_id', $this->id)->orderBy('date')->get();
        $numbers = Document::query()->whereKey($allocations->pluck('document_id'))->pluck('number', 'id');

        return [
            ...$data,
            'allocations' => $allocations->map(fn (Allocation $allocation) => [
                'id' => $allocation->id,
                'date' => $allocation->date->toDateString(),
                'document' => ['id' => $allocation->document_id, 'number' => $numbers[$allocation->document_id] ?? null],
                'amount' => Shapes::money($allocation->amount_minor, $allocation->currency),
                'fx_difference' => Shapes::money($allocation->fx_difference_minor, $this->base_currency),
                'journal_id' => $allocation->journal_id,
            ])->values()->all(),
        ];
    }
}
