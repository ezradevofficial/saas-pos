<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Models\DocumentWorkflow;

/** An `action` node being run for a document (WF-07, AUTO-03). */
final class ActionContext
{
    /**
     * @param  array<string, mixed>  $node  the node as stored in the flow version
     * @param  array<string, mixed>  $values  the document's field values
     */
    public function __construct(
        public readonly DocumentWorkflow $workflow,
        public readonly array $node,
        public readonly DocumentType $type,
        public readonly DocumentScope $scope,
        public readonly array $values,
        public readonly ?User $user,
    ) {}

    public function documentId(): string
    {
        return $this->workflow->document_id;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return is_array($this->node['config'] ?? null) ? $this->node['config'] : [];
    }
}
