<?php

namespace Modules\Accounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Models\BankAccount;
use Modules\Accounting\Models\BankRule;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Models\RecurringJournal;

/**
 * The smaller Accounting records as the API shows them: periods, posting
 * rules and exceptions, bank accounts, rules and statement lines,
 * recurring journals and budgets.
 */
class RecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $model = $this->resource;

        return match (true) {
            $model instanceof Period => [
                'id' => $model->id, 'company_id' => $model->company_id, 'fiscal_year' => $model->fiscal_year, 'number' => $model->number,
                'starts_on' => $model->starts_on->toDateString(), 'ends_on' => $model->ends_on->toDateString(), 'status' => $model->status,
                'closed_at' => $model->closed_at?->toIso8601String(), 'closed_by' => Shapes::user($model->closed_by),
                'reopened_at' => $model->reopened_at?->toIso8601String(), 'reopened_by' => Shapes::user($model->reopened_by), 'reopen_reason' => $model->reopen_reason,
                'closing_journal_id' => $model->closing_journal_id,
                'revalued' => $model->getAttribute('revalued'),
            ],
            $model instanceof PostingRule => [
                'id' => $model->id, 'company_id' => $model->company_id, 'event_type' => $model->event_type, 'description' => $model->description,
                'lines' => $model->lines, 'archived_at' => $model->archived_at?->toIso8601String(), 'created_at' => $model->created_at?->toIso8601String(),
            ],
            $model instanceof PostingException => [
                'id' => $model->id, 'company_id' => $model->company_id, 'source_type' => $model->source_type, 'source_id' => $model->source_id,
                'error_code' => $model->error_code, 'error_message' => $model->error_message, 'attempts' => $model->attempts, 'status' => $model->status,
                'journal_id' => $model->journal_id, 'last_attempt_at' => $model->last_attempt_at->toIso8601String(),
                'amounts' => $model->payload['amounts'] ?? [], 'currency' => $model->payload['currency'] ?? null, 'date' => $model->payload['date'] ?? null,
                // ACC-17: where the source opens in its own module.
                'reference' => $model->payload['reference'] ?? null, 'link' => $model->payload['link'] ?? null,
            ],
            $model instanceof BankAccount => [
                'id' => $model->id, 'company_id' => $model->company_id, 'name' => $model->name, 'kind' => $model->kind, 'account_id' => $model->account_id,
                'currency' => $model->currency, 'bank_name' => $model->bank_name, 'account_number' => $model->account_number,
                'payment_method_id' => $model->payment_method_id, 'archived_at' => $model->archived_at?->toIso8601String(),
            ],
            $model instanceof BankRule => [
                'id' => $model->id, 'company_id' => $model->company_id, 'bank_account_id' => $model->bank_account_id, 'name' => $model->name,
                'contains' => $model->contains, 'direction' => $model->direction, 'account_id' => $model->account_id, 'cost_centre_id' => $model->cost_centre_id,
                'archived_at' => $model->archived_at?->toIso8601String(),
            ],
            $model instanceof BankStatementLine => [
                'id' => $model->id, 'bank_account_id' => $model->bank_account_id, 'date' => $model->date->toDateString(), 'description' => $model->description,
                'reference' => $model->reference, 'amount' => Shapes::money($model->amount_minor, $model->currency), 'status' => $model->status,
                'matched_at' => $model->matched_at?->toIso8601String(),
            ],
            $model instanceof RecurringJournal => [
                'id' => $model->id, 'company_id' => $model->company_id, 'name' => $model->name, 'frequency' => $model->frequency,
                'next_run_on' => $model->next_run_on->toDateString(), 'ends_on' => $model->ends_on?->toDateString(), 'last_run_on' => $model->last_run_on?->toDateString(),
                'status' => $model->status, 'currency' => $model->currency, 'memo' => $model->memo, 'lines' => $model->lines,
                'archived_at' => $model->archived_at?->toIso8601String(),
            ],
            $model instanceof Budget => [
                'id' => $model->id, 'company_id' => $model->company_id, 'name' => $model->name, 'fiscal_year' => $model->fiscal_year,
                'archived_at' => $model->archived_at?->toIso8601String(),
                'cells' => $model->relationLoaded('lines') ? $model->lines->map(fn ($line) => [
                    'account_id' => $line->account_id, 'cost_centre_id' => $line->cost_centre_id, 'month' => $line->month, 'amount_minor' => (string) $line->amount_minor,
                ])->values()->all() : null,
            ],
            default => [],
        };
    }
}
