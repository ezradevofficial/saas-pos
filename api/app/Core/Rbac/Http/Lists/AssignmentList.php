<?php

namespace App\Core\Rbac\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Rbac\Http\Resources\AssignmentResource;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ScopeNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One user's role assignments (RBAC-04): sort keys and exportable columns
 * (EXP-01). The controller limits them to the reader's scope. No field
 * rules apply.
 */
class AssignmentList extends ListDefinition
{
    public function __construct(private readonly User $holder) {}

    public function name(): string
    {
        return 'user-roles';
    }

    public function auditAction(): string
    {
        return 'core.assignment.export';
    }

    public function title(array $filters): string
    {
        return __('core.assignment.list_title', ['name' => $this->holder->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return AssignmentResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'role' => ListSort::by(['role'], fn (Builder $query, string $direction) => $query->orderByRaw(
                "(select r.name from roles r where r.id = role_assignments.role_id) {$direction}",
            )),
            'scope_type' => ListSort::column('scope_type', ['scope']),
            'granted_at' => ListSort::column('created_at', ['granted_at']),
        ];
    }

    public function defaultSort(): string
    {
        return 'granted_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('role', 'core.assignment.columns.role', ['role'], fn (array $row) => $row['role']['name'] ?? null),
            ListColumn::make('scope_type', 'core.assignment.columns.scope_type', ['scope'],
                fn (array $row, RoleAssignment $assignment, ExportValues $values) => $values->enum('core.assignment.scope_types', $row['scope']['type'])),
            ListColumn::make('scope', 'core.assignment.columns.scope', ['scope'],
                fn (array $row, RoleAssignment $assignment, ExportValues $values) => AssignmentLabels::scope($values, $row['scope']['type'], $row['scope']['name'])),
            ListColumn::make('granted_by', 'core.assignment.columns.granted_by', ['granted_by'], fn (array $row) => $row['granted_by']['name'] ?? null),
            ListColumn::make('granted_at', 'core.assignment.columns.granted_at', ['granted_at'],
                fn (array $row, RoleAssignment $assignment, ExportValues $values) => $values->dateTime($row['granted_at'])),
        ];
    }

    public function exportRelations(): array
    {
        return ['role:id,name,is_owner', 'creator:id,name'];
    }

    /** The assignment with its scope's name (AssignmentResource reads it). */
    public function resolve(Model $model, Request $request): array
    {
        app(ScopeNames::class)->attach([$model]);

        return parent::resolve($model, $request);
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return ($filters['search'] ?? '') === '' ? [] : [__('core.list.search') => $filters['search']];
    }
}
