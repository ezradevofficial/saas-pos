<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * APR-02 `branch_manager`: holders of the Branch Manager system role
 * (template `branch_manager`) assigned at the document's branch; when
 * there are none (or the document has no branch), holders of that role
 * assigned at the document's company.
 */
class BranchManagerResolver implements ApproverResolver
{
    public function __construct(
        private readonly RoleRefs $roles,
        private readonly ApproverDirectory $directory,
    ) {}

    public function key(): string
    {
        return 'branch_manager';
    }

    public function label(): string
    {
        return 'approvals.approver_types.branch_manager';
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
        $roleIds = $this->roles->resolve([RoleRefs::TEMPLATE_PREFIX.'branch_manager']);

        foreach (['branch', 'company'] as $level) {
            $scope = $subject->at($level);
            $found = $scope === null ? [] : $this->directory->holdersAt($roleIds, [$scope]);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    public function describe(array $approver): string
    {
        return __('approvals.approver_types.branch_manager');
    }
}
