<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowToken;

/**
 * A document at an approval node (APR-01): what an ApprovalHandler gets.
 * `node` is the node as stored in the flow version (its `approval` key
 * holds the handler's configuration).
 */
final class ApprovalStep
{
    /** @param array<string, mixed> $node */
    public function __construct(
        public readonly DocumentWorkflow $workflow,
        public readonly DocumentWorkflowToken $token,
        public readonly array $node,
        public readonly DocumentType $type,
        public readonly DocumentScope $scope,
    ) {}

    public function documentId(): string
    {
        return $this->workflow->document_id;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return is_array($this->node['approval'] ?? null) ? $this->node['approval'] : [];
    }
}
