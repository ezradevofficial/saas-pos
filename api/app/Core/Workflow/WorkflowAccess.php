<?php

namespace App\Core\Workflow;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Models\WorkflowDefinition;

/**
 * RBAC-04 for flows (WF-02, spec 6.4) and documents in them (WF-10):
 *
 * - a company's flow is seen with `core.workflow.view` (or edit, publish)
 *   at, above or beneath the company; the flow for every company with it
 *   anywhere; edited with `core.workflow.edit` and published (or rolled
 *   back) with `core.workflow.publish` at the company, or at tenant scope
 *   for the flow of every company;
 * - a document's flow status and history are seen by people holding the
 *   type's view or act permission at the document's scope;
 *   `core.workflow.view` is for designing flows, never for documents.
 */
class WorkflowAccess
{
    public const PERMISSIONS = ['core.workflow.view', 'core.workflow.edit', 'core.workflow.publish'];

    public function __construct(private readonly CompanyReach $reach) {}

    public function anywhere(User $user): bool
    {
        return $this->reach->anywhere($user, self::PERMISSIONS);
    }

    public function sees(User $user, WorkflowDefinition $definition): bool
    {
        return $definition->tenant_id === $user->tenant_id
            && $this->reach->reachesRecord($user, $definition->company_id, self::PERMISSIONS);
    }

    /** @param 'edit'|'publish' $action */
    public function may(User $user, string $action, ?string $companyId): bool
    {
        $scope = $companyId === null ? Scope::tenant() : Scope::company($companyId);

        return $user->can('core.workflow.'.$action, $scope);
    }

    /** Whether the user reaches $company for flows at all (else it is not found for them). */
    public function reachesCompany(User $user, Company $company): bool
    {
        return $this->reach->reaches($user, $company, [...self::PERMISSIONS, 'core.company.view']);
    }

    public function seesDocument(User $user, DocumentType $type, DocumentScope $scope): bool
    {
        foreach ([$type->viewPermission(), $type->actPermission()] as $permission) {
            if ($user->can($permission, $scope->scope())) {
                return true;
            }
        }

        return false;
    }
}
