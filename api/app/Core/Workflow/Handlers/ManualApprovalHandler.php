<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * Until the approvals service is installed, an approval node is a stage:
 * people with its exit roles (or the type's act permission) complete it
 * as approved or rejected through the move endpoint. Its `approval`
 * configuration is stored as given and only checked to be an object.
 */
class ManualApprovalHandler implements ApprovalHandler
{
    public function validate(array $node, DocumentType $type): array
    {
        $config = $node['approval'] ?? [];

        return is_array($config) && ($config === [] || ! array_is_list($config))
            ? []
            : [__('workflow.validation.approval_config', ['node' => $node['id'] ?? ''])];
    }

    public function entered(ApprovalStep $step): void {}

    public function left(ApprovalStep $step, string $why): void {}

    public function allowsManualCompletion(ApprovalStep $step): bool
    {
        return true;
    }

    public function holders(ApprovalStep $step): ?array
    {
        return null;
    }
}
