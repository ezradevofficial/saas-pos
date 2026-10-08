<?php

namespace App\Core\Identity\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Identity\Http\Resources\UserAdminResource;
use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Rbac\Http\Lists\AssignmentLabels;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The users list (AUTH-13): sort keys and exportable columns (EXP-01).
 * Roles are only those held where the reader holds `core.user.view`
 * (RBAC-04), as in the JSON list. No field rules apply to users.
 */
class UserList extends ListDefinition
{
    public const PERMISSION = 'core.user.view';

    private ?VisibleScope $visible = null;

    public function __construct(private readonly User $reader) {}

    /** Where the reader sees users and their roles (RBAC-04), read once. */
    public function visible(): VisibleScope
    {
        return $this->visible ??= app(ScopeResolver::class)->visibleIds($this->reader, self::PERMISSION);
    }

    public function name(): string
    {
        return 'users';
    }

    public function auditAction(): string
    {
        return 'core.user.export';
    }

    public function title(array $filters): string
    {
        return __('core.user.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return UserAdminResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            'email' => ListSort::column('email'),
            'phone' => ListSort::column('phone'),
            'status' => ListSort::column('status'),
            'last_sign_in_at' => ListSort::column('last_sign_in_at'),
            'created_at' => ListSort::column('created_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('name', 'core.user.columns.name'),
            ListColumn::text('email', 'core.user.columns.email'),
            ListColumn::text('phone', 'core.user.columns.phone'),
            ListColumn::make('roles', 'core.user.columns.roles', ['roles'], function (array $row, User $user, ExportValues $values) {
                app(ScopeNames::class)->attach($user->assignments);

                return $values->join($user->assignments->map(fn ($a) => AssignmentLabels::roleAt($values, $a->role?->name, $a->scope_type, $a->scopeName))->all());
            }),
            ListColumn::make('status', 'core.user.columns.status', ['status'],
                fn (array $row, User $user, ExportValues $values) => $values->enum('core.user.statuses', $row['status'])),
            ListColumn::make('two_factor', 'core.user.columns.two_factor', ['two_factor_enabled'],
                fn (array $row) => __('core.list.'.($row['two_factor_enabled'] ? 'yes' : 'no'))),
            ListColumn::make('last_sign_in_at', 'core.user.columns.last_sign_in_at', ['last_sign_in_at'],
                fn (array $row, User $user, ExportValues $values) => $values->dateTime($row['last_sign_in_at'])),
            ListColumn::make('created_at', 'core.user.columns.created_at', ['created_at'],
                fn (array $row, User $user, ExportValues $values) => $values->dateTime($row['created_at'])),
        ];
    }

    public function exportRelations(): array
    {
        return [
            // Only the roles held where the reader sees (RBAC-04).
            'assignments' => fn ($query) => ScopeNames::constrain($query->getQuery(), $this->visible())->orderBy('created_at')->orderBy('id'),
            'assignments.role:id,name,is_owner',
            'assignments.creator:id,name',
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = [];

        if (($filters['search'] ?? '') !== '') {
            $summary[__('core.list.search')] = $filters['search'];
        }

        $status = $filters['status'] ?? 'all';
        $summary[__('core.list.status')] = $status === 'all' ? __('core.list.statuses.all') : (string) $values->enum('core.user.statuses', $status);

        return $summary;
    }
}
