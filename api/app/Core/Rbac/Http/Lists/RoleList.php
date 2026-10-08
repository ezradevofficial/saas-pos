<?php

namespace App\Core\Rbac\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Rbac\Http\Resources\RoleResource;
use App\Core\Rbac\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The roles list (RBAC-02): sort keys and exportable columns (EXP-01). No
 * field rules apply to roles.
 */
class RoleList extends ListDefinition
{
    public function name(): string
    {
        return 'roles';
    }

    public function auditAction(): string
    {
        return 'core.role.export';
    }

    public function title(array $filters): string
    {
        return __('core.role.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return RoleResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            // Ascending: system roles first, then by name (the list's order before sorting existed).
            'type' => ListSort::by(['is_system', 'name'], fn (Builder $query, string $direction) => $query
                ->orderBy($query->qualifyColumn('is_system'), $direction === 'asc' ? 'desc' : 'asc')
                ->orderBy($query->qualifyColumn('name'), $direction)),
            'two_factor' => ListSort::column('requires_two_factor'),
        ];
    }

    public function defaultSort(): string
    {
        return 'type';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('name', 'core.role.columns.name'),
            ListColumn::text('description', 'core.role.columns.description'),
            ListColumn::make('type', 'core.role.columns.type', ['is_system'],
                fn (array $row) => __('core.role.types.'.($row['is_system'] ? 'system' : 'custom'))),
            ListColumn::make('permissions', 'core.role.columns.permissions', ['permissions'],
                fn (array $row, Role $role, ExportValues $values) => $values->integer(count($row['permissions'] ?? []))),
            ListColumn::make('two_factor', 'core.role.columns.two_factor', ['requires_two_factor'],
                fn (array $row) => $row['requires_two_factor'] ? __('core.role.two_factor_required') : null),
            ListColumn::make('status', 'core.role.columns.status', ['archived_at'],
                fn (array $row) => __('core.list.statuses.'.($row['archived_at'] === null ? 'active' : 'archived'))),
        ];
    }

    public function exportRelations(): array
    {
        return ['permissions:id,name'];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = [];

        if (($filters['search'] ?? '') !== '') {
            $summary[__('core.list.search')] = $filters['search'];
        }

        $summary[__('core.list.status')] = __('core.list.statuses.'.($filters['status'] ?? 'active'));

        return $summary;
    }
}
