<?php

namespace Modules\Payroll\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Resources\LoanResource;
use Modules\Payroll\Support\PayFields;

/** PAY-13, PAY-25: loans and advances, amounts hidden with `amounts` (PAY-23). */
class LoanList extends PayrollList
{
    public function name(): string
    {
        return 'payroll-loans';
    }

    public function auditAction(): string
    {
        return 'payroll.loan.export';
    }

    public function title(array $filters): string
    {
        return __('payroll.lists.loans');
    }

    public function resource(Model $model): JsonResource
    {
        return LoanResource::make($model);
    }

    public function fieldSources(): array
    {
        return array_fill_keys(['principal', 'instalment', 'balance', 'entries'], [PayFields::AMOUNTS]);
    }

    public function sorts(): array
    {
        return ['disbursed_on' => ListSort::column('disbursed_on'), 'starts_on' => ListSort::column('starts_on'), 'status' => ListSort::column('status')];
    }

    public function defaultSort(): string
    {
        return '-disbursed_on';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('employee', 'payroll.columns.employee', ['employee'], fn (array $row) => isset($row['employee']) ? $row['employee']['number'].' '.$row['employee']['name'] : null),
            ListColumn::make('kind', 'payroll.columns.kind', ['kind'], fn (array $row) => __('payroll.loans.kinds.'.$row['kind'])),
            self::text('reference'),
            self::money('principal'),
            self::money('instalment'),
            self::money('balance'),
            self::date('disbursed_on'),
            self::date('starts_on'),
            self::status('status', 'payroll.loans.statuses.'),
        ];
    }

    public function exportRelations(): array
    {
        return ['employee'];
    }
}
