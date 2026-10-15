<?php

namespace Modules\Payroll\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Resources\RunResource;
use Modules\Payroll\Support\PayFields;

/** PAY-14, PAY-25: payroll runs. */
class RunList extends PayrollList
{
    public const RELATIONS = ['company', 'payGroup'];

    public function name(): string
    {
        return 'payroll-runs';
    }

    public function auditAction(): string
    {
        return 'payroll.run.export';
    }

    public function title(array $filters): string
    {
        return __('payroll.lists.runs');
    }

    public function resource(Model $model): JsonResource
    {
        return RunResource::make($model);
    }

    public function fieldSources(): array
    {
        return ['totals' => [PayFields::TOTALS]];
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('number'),
            'period_start' => ListSort::column('period_start'),
            'pay_date' => ListSort::column('pay_date'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-period_start';
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::named('company'),
            self::named('pay_group'),
            ListColumn::make('kind', 'payroll.columns.kind', ['kind'], fn (array $row) => __('payroll.kinds.'.$row['kind'])),
            self::date('period_start'),
            self::date('period_end'),
            self::date('pay_date'),
            self::status(),
            self::text('employees'),
            ListColumn::make('net_base', 'payroll.columns.net_base', ['totals', PayFields::TOTALS], fn (array $row, $model, ExportValues $values) => $values->money($row['totals']['base']['net'] ?? null)),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
