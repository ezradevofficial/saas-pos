<?php

namespace Modules\Accounting\Http\Resources;

use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Ledger\JournalType;
use Modules\Accounting\Ledger\Reversals;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/** @mixin Journal */
class JournalResource extends JsonResource
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
            'number' => $this->number,
            'date' => $this->date->toDateString(),
            'kind' => $this->kind,
            'status' => $this->status,
            'reference' => $this->reference,
            'memo' => $this->memo,
            'total' => Shapes::money($this->total_base_minor, $this->base_currency),
            'source' => Shapes::source($this->source_type, $this->source_id),
            'reverses_journal_id' => $this->reverses_journal_id,
            'reversed_by_journal_id' => $this->reversed_by_journal_id,
            'post_error' => $this->post_error,
            'created_by' => Shapes::user($this->created_by),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'editable' => $this->isEditable(),
            // ACC-05: whether a person may reverse it (subledger, revaluation and closing journals are corrected otherwise).
            'reversible' => $this->isPosted() && $this->reversed_by_journal_id === null && $this->kind !== 'reversal' && Reversals::reversible($this->resource),
        ];

        if (! $this->detail) {
            return $data;
        }

        $flow = in_array($this->kind, Journal::EDITABLE_KINDS, true) ? app(WorkflowEngine::class)->current(JournalType::KEY, $this->id) : null;

        return [
            ...$data,
            'period_id' => $this->period_id,
            'posted_by' => Shapes::user($this->posted_by),
            'decided_by' => Shapes::user($this->decided_by),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'workflow' => $flow === null ? null : ['id' => $flow->id, 'status' => $flow->status, 'outcome' => $flow->outcome ?? null],
            'lines' => $this->lines->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'account' => $line->account === null ? null : ['id' => $line->account->id, 'code' => $line->account->code, 'name' => $line->account->name],
                'description' => $line->description,
                'debit' => Shapes::money($line->debit_minor, $line->currency),
                'credit' => Shapes::money($line->credit_minor, $line->currency),
                'base_debit' => Shapes::money($line->base_debit_minor, $this->base_currency),
                'base_credit' => Shapes::money($line->base_credit_minor, $this->base_currency),
                'fx' => $line->fx?->jsonSerialize(),
                'party' => Shapes::named($line->party),
                'cost_centre' => Shapes::named($line->costCentre),
                'department' => Shapes::named($line->department),
                'project' => Shapes::named($line->project),
                'branch_id' => $line->branch_id,
            ])->values()->all(),
        ];
    }
}
