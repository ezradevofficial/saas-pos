<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Capabilities\AssignsUsers;
use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\WorkflowAccess;
use Illuminate\Support\Str;

/**
 * AUTO-03 "assign user": `{"type": "assign_user", "field": "owner",
 * "user": "<user id>"}`. Only fields the type lists as assignable
 * (AssignsUsers); the user must be an active user of the tenant who may
 * see the document (RBAC-04), checked again when the rule runs.
 */
class AssignUserAction implements AutomationAction
{
    public const KEY = 'assign_user';

    public function __construct(private readonly WorkflowAccess $access) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function needsDocument(): bool
    {
        return true;
    }

    public function validate(array $action, RuleContext $rule): array
    {
        if (! $rule->type instanceof AssignsUsers) {
            return [__('automation.validation.capability_missing', ['action' => __('automation.actions.'.self::KEY.'.label')])];
        }

        $problems = [];
        $field = $action['field'] ?? null;

        if (! is_string($field) || ! in_array($field, Capabilities::assignableFields($rule->type), true)) {
            $problems[] = __('automation.validation.field_not_assignable', ['field' => is_string($field) ? $field : '']);
        }

        if ($this->user($action) === null) {
            $problems[] = __('automation.validation.unknown_user');
        }

        if (array_diff(array_keys($action), ['type', 'field', 'user']) !== []) {
            $problems[] = __('automation.validation.action_extra');
        }

        return $problems;
    }

    public function requiredPermissions(array $action, DocumentType $type): array
    {
        return [$type->actPermission()];
    }

    public function describe(array $action, AutomationContext $context): string
    {
        $field = $context->type->field((string) ($action['field'] ?? ''));

        return __('automation.actions.assign_user.describe', [
            'user' => $this->user($action)?->name ?? (string) ($action['user'] ?? ''),
            'field' => $field === null ? (string) ($action['field'] ?? '') : __($field->label),
        ]);
    }

    public function run(array $action, AutomationContext $context): array
    {
        $documentId = $context->requireDocument();
        $field = (string) ($action['field'] ?? '');
        $user = $this->user($action);

        if (! $context->type instanceof AssignsUsers || ! in_array($field, Capabilities::assignableFields($context->type), true)) {
            throw new ActionFailed(__('automation.errors.field_not_assignable', ['field' => $field]));
        }

        if ($user === null) {
            throw new ActionFailed(__('automation.errors.user_unavailable'));
        }

        if (! $this->access->seesDocument($user, $context->type, $context->scope)) {
            throw new ActionFailed(__('automation.errors.user_cannot_see', ['user' => $user->name]));
        }

        $context->type->assignUser($documentId, $field, $user->id, $context->actor);

        return ['field' => $field, 'user_id' => $user->id];
    }

    /** The active user of the tenant (row-level security) the action names. */
    private function user(array $action): ?User
    {
        $id = $action['user'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return User::query()->whereKey($id)->where('status', User::STATUS_ACTIVE)->first();
    }
}
