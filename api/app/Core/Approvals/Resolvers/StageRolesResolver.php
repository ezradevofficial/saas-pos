<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * The approver of a node naming none (not offered in the builder): users
 * holding one of the node's exit roles at a place covering the document,
 * else users holding the document type's act permission there (as a
 * stage without roles, WF-08).
 */
class StageRolesResolver implements ApproverResolver
{
    public function __construct(
        private readonly RoleRefs $roles,
        private readonly ApproverDirectory $directory,
    ) {}

    public function key(): string
    {
        return 'stage_roles';
    }

    public function label(): string
    {
        return 'approvals.approver_types.stage_roles';
    }

    public function params(): array
    {
        return [];
    }

    public function validate(array $approver, DocumentType $type): array
    {
        return [];
    }

    public function resolve(array $approver, ApprovalSubject $subject): array
    {
        $refs = is_array($subject->node['exit_roles'] ?? null) ? $subject->node['exit_roles'] : [];
        $roleIds = $refs === [] ? $this->directory->rolesWith($subject->type->actPermission()) : $this->roles->resolve($refs);

        return $this->directory->holdersAt($roleIds, $subject->chain);
    }

    public function describe(array $approver): string
    {
        return __('approvals.approver_types.stage_roles');
    }
}
