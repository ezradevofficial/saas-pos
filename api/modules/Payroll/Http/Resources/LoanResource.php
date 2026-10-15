<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanEntry;
use Modules\Payroll\Support\PayFields;

/**
 * PAY-13: a loan or advance with its balance and, in the detail, its
 * ledger. Amounts only when the reader's field rules do not hide them.
 *
 * @property Loan $resource
 */
class LoanResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $loan = $this->resource;
        $hidden = app(PayFields::class)->hides(PayFields::AMOUNTS, $request);
        $employee = $loan->relationLoaded('employee') ? $loan->employee : null;

        return [
            'id' => $loan->id,
            'employee' => $employee === null ? null : ['id' => $employee->id, 'number' => $employee->number, 'name' => $employee->name],
            'company_id' => $loan->company_id,
            'kind' => $loan->kind,
            'reference' => $loan->reference,
            'currency' => $loan->currency,
            ...($hidden ? [] : [
                'principal' => Refs::money($loan->principal_minor, $loan->currency),
                'instalment' => Refs::money($loan->instalment_minor, $loan->currency),
                'balance' => Refs::money($loan->getAttribute('balance_minor') ?? $loan->balance(), $loan->currency),
            ]),
            'disbursed_on' => $loan->disbursed_on->toDateString(),
            'starts_on' => $loan->starts_on->toDateString(),
            'settle_next_run' => $loan->settle_next_run,
            'status' => $loan->status,
            'note' => $loan->note,
            ...($this->detail && ! $hidden ? ['entries' => $loan->entries->map(fn (LoanEntry $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'amount' => Refs::money($e->amount_minor, $loan->currency), 'run_id' => $e->run_id,
                'entry_date' => $e->entry_date->toDateString(), 'note' => $e->note, 'created_at' => $e->created_at?->toIso8601String(),
            ])->all()] : []),
        ];
    }
}
