<?php

namespace Modules\Accounting\Http\Resources;

use App\Core\Fiscal\FiscalQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Documents\Documents;
use Modules\Accounting\Fiscal\AccountingFiscalSource;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;

/** @mixin Document */
class DocumentResource extends JsonResource
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
            'branch_id' => $this->branch_id,
            'kind' => $this->kind,
            'number' => $this->number,
            'status' => $this->status,
            'party' => Shapes::named($this->party),
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'currency' => $this->currency,
            'tax_inclusive' => $this->tax_inclusive,
            'supplier_reference' => $this->supplier_reference,
            'subtotal' => Shapes::money($this->subtotal_minor, $this->currency),
            'tax' => Shapes::money($this->tax_minor, $this->currency),
            'total' => Shapes::money($this->total_minor, $this->currency),
            'open' => Shapes::money($this->open_minor, $this->currency),
            'base_total' => $this->base_currency === null ? null : Shapes::money($this->base_total_minor, $this->base_currency),
            'fx' => $this->fx?->jsonSerialize(),
            'credits_document_id' => $this->credits_document_id,
            'journal_id' => $this->journal_id,
            'memo' => $this->memo,
            'posted_at' => $this->posted_at?->toIso8601String(),
        ];

        if (! $this->detail) {
            return $data;
        }

        $lines = $this->lines()->with(['account', 'taxCode'])->get();
        $allocations = Allocation::query()->where('document_id', $this->id)->orWhere('credit_document_id', $this->id)->orderBy('date')->orderBy('created_at')->get();

        return [
            ...$data,
            'rate_needed' => $this->status === Document::DRAFT && $lines->contains(fn (DocumentLine $line) => Documents::rateNeeded($line)),
            'lines' => $lines->map(fn (DocumentLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'description' => $line->description,
                'item_id' => $line->item_id,
                'account' => $line->account === null ? null : ['id' => $line->account->id, 'code' => $line->account->code, 'name' => $line->account->name],
                'qty' => rtrim(rtrim((string) $line->qty, '0'), '.'),
                'unit_price' => Shapes::money($line->unit_price_minor, $this->currency),
                'tax_code' => $line->taxCode === null ? null : ['id' => $line->taxCode->id, 'code' => $line->taxCode->code, 'name' => $line->taxCode->name],
                'tax_rate' => $line->tax_rate === null ? null : rtrim(rtrim((string) $line->tax_rate, '0'), '.'),
                'rate_needed' => Documents::rateNeeded($line),
                'net' => Shapes::money($line->net_minor, $this->currency),
                'tax' => Shapes::money($line->tax_minor, $this->currency),
                'total' => Shapes::money($line->total_minor, $this->currency),
                'cost_centre_id' => $line->cost_centre_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
            ])->values()->all(),
            'allocations' => $allocations->map(fn (Allocation $allocation) => [
                'id' => $allocation->id,
                'date' => $allocation->date->toDateString(),
                'amount' => Shapes::money($allocation->amount_minor, $allocation->currency),
                'payment_id' => $allocation->payment_id,
                'credit_document_id' => $allocation->credit_document_id,
                'document_id' => $allocation->document_id,
                'fx_difference' => Shapes::money($allocation->fx_difference_minor, (string) $this->base_currency),
            ])->values()->all(),
            'fiscal' => $this->status === Document::POSTED && str_starts_with($this->kind, 'customer')
                ? app(FiscalQueue::class)->statusFor(AccountingFiscalSource::KEY, AccountingFiscalSource::typeOf($this->kind), $this->id)
                : null,
        ];
    }
}
