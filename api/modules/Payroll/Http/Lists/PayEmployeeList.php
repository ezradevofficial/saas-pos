<?php

namespace Modules\Payroll\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Resources\PayEmployeeResource;

/** PAY-05, PAY-25: employees' pay setup. */
class PayEmployeeList extends PayrollList
{
    public const RELATIONS = ['company', 'branch', 'location', 'department', 'payGroup'];

    public function name(): string
    {
        return 'payroll-employees';
    }

    public function auditAction(): string
    {
        return 'payroll.employee.export';
    }

    public function title(array $filters): string
    {
        return __('payroll.lists.employees');
    }

    public function resource(Model $model): JsonResource
    {
        return PayEmployeeResource::make($model);
    }

    public function sorts(): array
    {
        return ['number' => ListSort::column('number'), 'name' => ListSort::column('name'), 'hire_date' => ListSort::column('hire_date')];
    }

    public function defaultSort(): string
    {
        return 'number';
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::text('name'),
            self::named('company'),
            self::named('branch'),
            self::named('department'),
            self::named('pay_group'),
            self::text('pay_currency'),
            ListColumn::make('payment_method', 'payroll.columns.payment_method', ['payment_method'], fn (array $row) => $row['payment_method'] === null ? null : __('payroll.methods.'.$row['payment_method'])),
            self::date('hire_date'),
            self::status('status', 'payroll.employee_statuses.'),
            ListColumn::make('on_hold', 'payroll.columns.on_hold', ['on_hold'], fn (array $row) => $row['on_hold'] ? __('payroll.yes') : __('payroll.no')),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
