<?php

namespace Modules\Payroll\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Resources\RunLineResource;
use Modules\Payroll\Support\PayFields;

/** PAY-14, PAY-15, PAY-25: a run's lines, amounts hidden with `amounts` (PAY-23). */
class RunLineList extends PayrollList
{
    public function name(): string
    {
        return 'payroll-run-lines';
    }

    public function auditAction(): string
    {
        return 'payroll.run.lines_export';
    }

    public function title(array $filters): string
    {
        return __('payroll.lists.run_lines');
    }

    public function resource(Model $model): JsonResource
    {
        return RunLineResource::make($model);
    }

    public function fieldSources(): array
    {
        return array_fill_keys(RunLineResource::AMOUNT_KEYS, [PayFields::AMOUNTS]);
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('number'),
            'name' => ListSort::column('name'),
            'gross' => ListSort::column('gross_minor', ['gross']),
            'net' => ListSort::column('net_minor', ['net']),
        ];
    }

    public function defaultSort(): string
    {
        return 'number';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('number', 'payroll.columns.number', ['employee'], fn (array $row) => $row['employee']['number'] ?? null),
            ListColumn::make('name', 'payroll.columns.name', ['employee'], fn (array $row) => $row['employee']['name'] ?? null),
            self::text('pay_currency'),
            self::money('basic'),
            self::money('gross'),
            self::money('statutory'),
            self::money('deductions'),
            self::money('loans'),
            self::money('net'),
            self::money('employer'),
            ListColumn::make('errors', 'payroll.columns.errors', ['errors'], fn (array $row) => implode('; ', array_column($row['errors'] ?? [], 'message'))),
        ];
    }
}
