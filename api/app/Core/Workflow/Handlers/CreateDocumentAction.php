<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\DocumentWorkflowLink;

/**
 * WF-07: create the next document, e.g. a draft purchase order from an
 * approved requisition. Config: `mapping` (a NextDocument key of the
 * flow's type) and `on_cancel` (`keep` or `cancel`, WF-11: what happens to
 * the created document when this flow is cancelled; default keep). The
 * target type creates the draft from the mapped values; the link is kept.
 */
class CreateDocumentAction implements ActionHandler
{
    public const KEY = 'create_document';

    public function __construct(private readonly DocumentTypeRegistry $types) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(array $config, DocumentType $type): array
    {
        $problems = [];
        $mapping = $config['mapping'] ?? null;
        $next = is_string($mapping) ? $type->nextDocument($mapping) : null;

        if ($next === null) {
            $problems[] = __('workflow.validation.unknown_mapping', ['mapping' => is_string($mapping) ? $mapping : '']);
        } elseif ($this->types->find($next->target) === null) {
            $problems[] = __('workflow.validation.unknown_target', ['type' => $next->target]);
        }

        if (isset($config['on_cancel']) && ! in_array($config['on_cancel'], DocumentWorkflowLink::ON_CANCEL, true)) {
            $problems[] = __('workflow.validation.on_cancel');
        }

        return $problems;
    }

    public function run(ActionContext $context): array
    {
        $config = $context->config();
        $next = $context->type->nextDocument((string) $config['mapping']);
        $target = $this->types->get($next->target);

        $documentId = $target->createDraft($next->map($context->values), $context->scope, $context->user);

        DocumentWorkflowLink::create([
            'workflow_id' => $context->workflow->id,
            'node_id' => (string) $context->node['id'],
            'mapping' => $next->key,
            'target_type' => $next->target,
            'target_document_id' => $documentId,
            'on_cancel' => $config['on_cancel'] ?? 'keep',
        ]);

        return ['mapping' => $next->key, 'target_type' => $next->target, 'document_id' => $documentId];
    }

    public function describe(array $config, DocumentType $type): string
    {
        $next = is_string($config['mapping'] ?? null) ? $type->nextDocument($config['mapping']) : null;
        $target = $next === null ? null : $this->types->find($next->target);

        return __('workflow.actions.create_document', ['document' => $target === null ? ($next?->target ?? '') : __($target->label())]);
    }
}
