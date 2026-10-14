<?php

namespace Modules\HR\Employees;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Access;
use Modules\HR\Support\Sensitive;

/**
 * CF-01, HR-02: employees carry custom fields (`custom` jsonb), offered
 * while the tenant has HR active (RBAC-08). Who sees an employee follows
 * `hr.employee.view` at its place (HR-38); field rules resource
 * `hr_employee` (RBAC-05).
 */
class EmployeeEntity extends CustomFieldEntity
{
    public const KEY = 'hr_employee';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'hr.custom_fields.entity';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function fieldRules(): ?string
    {
        return Sensitive::RESOURCE;
    }

    public function viewAny(User $actor): bool
    {
        return app(Access::class)->anywhere($actor, 'hr.employee.view');
    }

    public function view(User $actor, Model $record): bool
    {
        return $record instanceof Employee && app(Access::class)->can($actor, 'hr.employee.view', $record);
    }

    public function writeAny(User $actor): bool
    {
        $access = app(Access::class);

        return $access->anywhere($actor, 'hr.employee.create') || $access->anywhere($actor, 'hr.employee.edit');
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(Access::class)->limit($query, $actor, 'hr.employee.view');
    }

    public function display(Model $record): string
    {
        return $record->number.' '.$record->displayName();
    }

    public function search(User $actor, string $search, int $limit): array
    {
        if (! $this->viewAny($actor)) {
            return [];
        }

        $query = $this->visible($this->newQuery(), $actor)->where('status', Employee::ACTIVE);
        $this->matching($query, trim($search));

        return $query->limit($limit)->get()
            ->map(fn (Model $record) => ['id' => (string) $record->getKey(), 'label' => $this->display($record)])->values()->all();
    }

    protected function matching(Builder $query, string $search): void
    {
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like)->orWhere('number', 'ilike', $like));
        }

        $query->orderBy('last_name')->orderBy('first_name')->orderBy('id');
    }
}
