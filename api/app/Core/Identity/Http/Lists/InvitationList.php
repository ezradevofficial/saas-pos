<?php

namespace App\Core\Identity\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Identity\Http\Resources\InvitationResource;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Rbac\Http\Lists\AssignmentLabels;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The invitations list (AUTH-05): sort keys and exportable columns
 * (EXP-01). Only the roles granted inside the reader's
 * `core.user.invite` scope are listed, and the inviter is named only when
 * the reader may see that user (RBAC-04). No field rules apply.
 */
class InvitationList extends ListDefinition
{
    public const PERMISSION = 'core.user.invite';

    private ?VisibleScope $visible = null;

    private ?VisibleUserNames $users = null;

    public function __construct(private readonly User $reader) {}

    /** Where the reader manages invitations (RBAC-04), read once. */
    public function visible(): VisibleScope
    {
        return $this->visible ??= app(ScopeResolver::class)->visibleIds($this->reader, self::PERMISSION);
    }

    public function name(): string
    {
        return 'invitations';
    }

    public function auditAction(): string
    {
        return 'core.invitation.export';
    }

    public function title(array $filters): string
    {
        return __('core.invitation.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return InvitationResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            'email' => ListSort::column('email'),
            'phone' => ListSort::column('phone'),
            'expires_at' => ListSort::column('expires_at'),
            'created_at' => ListSort::column('created_at'),
        ];
    }

    public function defaultSort(): string
    {
        // Newest first, as before sorting existed.
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('name', 'core.invitation.columns.name'),
            ListColumn::text('email', 'core.invitation.columns.email'),
            ListColumn::text('phone', 'core.invitation.columns.phone'),
            ListColumn::make('roles', 'core.invitation.columns.roles', ['assignments'],
                fn (array $row, Invitation $invitation, ExportValues $values) => $values->join($this->roles($row['assignments'] ?? [], $values))),
            ListColumn::make('status', 'core.invitation.columns.status', ['status'],
                fn (array $row, Invitation $invitation, ExportValues $values) => $values->enum('core.invitation.statuses', $row['status'])),
            ListColumn::make('invited_by', 'core.invitation.columns.invited_by', ['invited_by'],
                // Named only when the reader may see the inviter (RBAC-04).
                fn (array $row) => ($this->users ??= new VisibleUserNames($this->reader))->label($row['invited_by'])),
            ListColumn::make('expires_at', 'core.invitation.columns.expires_at', ['expires_at'],
                fn (array $row, Invitation $invitation, ExportValues $values) => $values->dateTime($row['expires_at'])),
            ListColumn::make('created_at', 'core.invitation.columns.created_at', ['created_at'],
                fn (array $row, Invitation $invitation, ExportValues $values) => $values->dateTime($row['created_at'])),
        ];
    }

    /**
     * "Role at where" for each assignment the invitation will grant inside
     * the reader's scope; grants elsewhere are left out (RBAC-04), as in
     * the users export.
     *
     * @param  list<array{role_id?: string, scope_type?: string, scope_id?: ?string}>  $assignments
     * @return list<string>
     */
    private function roles(array $assignments, ExportValues $values): array
    {
        $visible = $this->visible();
        $grants = array_values(array_filter(
            array_map(fn (array $a) => (object) ['role_id' => $a['role_id'] ?? null, 'scope_type' => $a['scope_type'] ?? '', 'scope_id' => $a['scope_id'] ?? null], $assignments),
            fn (object $g) => $g->scope_id !== null && ScopeNames::covers($visible, $g->scope_type, $g->scope_id),
        ));
        $roles = Role::query()->whereKey(array_filter(array_column($grants, 'role_id')))->pluck('name', 'id');
        app(ScopeNames::class)->attach($grants);

        return array_map(fn (object $g) => AssignmentLabels::roleAt($values, $roles[$g->role_id] ?? null, $g->scope_type, $g->scopeName ?? null), $grants);
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return [
            ...$this->searchAndStatus($filters, archivable: false),
            __('core.list.status') => __('core.invitation.status_filter.'.($filters['status'] ?? 'open')),
        ];
    }
}
