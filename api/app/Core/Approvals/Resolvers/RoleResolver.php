<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Rbac\Models\Role;
use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * APR-02 `role` (`role`: a role id or `template:<key>`): active users
 * holding that role at a place covering the document (RBAC-04: the
 * tenant, its company, branch or location).
 */
class RoleResolver implements ApproverResolver
{
    public function __construct(
        private readonly RoleRefs $roles,
        private readonly ApproverDirectory $directory,
    ) {}

    public function key(): string
    {
        return 'role';
    }

    public function label(): string
    {
        return 'approvals.approver_types.role';
    }

    public function params(): array
    {
        return [['name' => 'role', 'type' => 'role', 'required' => true]];
    }

    public function validate(array $approver, DocumentType $type): array
    {
        $role = $approver['role'] ?? null;

        return is_string($role) && $this->roles->unknown([$role]) === []
            ? []
            : [__('approvals.validation.role')];
    }

    public function resolve(array $approver, ApprovalSubject $subject): array
    {
        $role = $approver['role'] ?? null;

        return is_string($role) ? $this->directory->holdersAt($this->roles->resolve([$role]), $subject->chain) : [];
    }

    public function describe(array $approver): string
    {
        $role = is_string($approver['role'] ?? null) ? $this->roles->resolve([$approver['role']]) : [];
        $name = $role === [] ? null : Role::query()->whereKey($role[0])->value('name');

        return $name === null ? __('approvals.approver_types.role') : (string) $name;
    }
}
