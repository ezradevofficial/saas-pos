<?php

namespace Modules\HR\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\EmployeeResource;
use Modules\HR\Support\Access;
use Modules\HR\Support\Sensitive;

/**
 * HR-35, HR-09: employees. Field rules resource `hr_employee`; date of
 * birth and pay are also left out of the export for a reader who holds
 * their group's view permission nowhere (rows show them only where the
 * reader sees them, EmployeeResource).
 */
class EmployeeList extends HrList
{
    public const RELATIONS = ['company', 'branch', 'location', 'department', 'position', 'manager', 'user', 'currentContract'];

    public function name(): string
    {
        return 'hr-employees';
    }

    public function auditAction(): string
    {
        return 'hr.employee.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.employees');
    }

    public function fieldRules(): ?string
    {
        return Sensitive::RESOURCE;
    }

    public function hiddenFields(Request $request): array
    {
        $hidden = parent::hiddenFields($request);
        $user = $request->user();

        if ($user !== null) {
            $access = app(Access::class);

            if (! $access->anywhere($user, 'hr.identity.view')) {
                $hidden[] = 'date_of_birth';
            }

            if (! $access->anywhere($user, 'hr.pay.view')) {
                $hidden[] = 'pay';
            }
        }

        return array_values(array_unique($hidden));
    }

    public function resource(Model $model): JsonResource
    {
        return EmployeeResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('number'),
            'last_name' => ListSort::column('last_name'),
            'hire_date' => ListSort::column('hire_date'),
            'status' => ListSort::column('status'),
            'created_at' => ListSort::column('created_at'),
        ];
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
            self::named('company', 'company'),
            self::named('branch', 'branch'),
            self::named('location', 'location'),
            ListColumn::make('department', 'hr.columns.department', ['department'], fn (array $row) => $row['department']['name'] ?? null),
            ListColumn::make('position', 'hr.columns.position', ['position'], fn (array $row) => $row['position']['title'] ?? null),
            self::status(),
            self::date('hire_date'),
            self::text('work_email'),
            self::text('work_phone'),
            self::text('personal_phone'),
            self::date('date_of_birth'),
            ListColumn::make('pay', 'hr.columns.pay', ['pay'], fn (array $row, $model, ExportValues $values) => isset($row['pay']) ? $values->money($row['pay']) : null),
        ];
    }

    public function customFieldEntity(): ?string
    {
        return 'hr_employee';
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
