<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\DocumentWorkflowLink;
use App\Core\Workflow\Runtime\WorkflowBlocked;

/**
 * WF-07: create the next document, e.g. a draft purchase order from an
 * approved requisition. Config: `mapping` (a NextDocument key of the
 * flow's type) and `on_cancel` (`keep` or `cancel`, WF-11: what happens to
 * the created document when this flow is cancelled; default keep). The
 * target type creates the draft from the mapped values; the link is kept.
 * Passing the node again (after a return) keeps the document it created
 * unless that was cancelled; the history's action event then says
 * `kept: true`.
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
        $next = $context->type->nextDocument((string) ($config['mapping'] ?? ''));
        $target = $next === null ? null : $this->types->find($next->target);

        // The target's module was switched off (or the mapping withdrawn) after publishing.
        if ($target === null) {
            $step = is_string($context->node['name'] ?? null) && $context->node['name'] !== '' ? $context->node['name'] : (string) $context->node['id'];

            throw new WorkflowBlocked('next_document_unavailable', __('workflow.errors.next_document_unavailable', ['stage' => $step]), [], (string) $context->node['id']);
        }

        // M3: the flow passes here again after a return (WF-11): the document
        // it created the first time is kept unless it was cancelled.
        $existing = DocumentWorkflowLink::query()
            ->where('workflow_id', $context->workflow->id)
            ->where('node_id', (string) $context->node['id'])
            ->where('target_type', $next->target)
            ->whereNull('cancelled_at')
            ->latest('created_at')
            ->first();

        if ($existing !== null && ! $target->isCancelled($existing->target_document_id)) {
            return ['mapping' => $next->key, 'target_type' => $next->target, 'document_id' => $existing->target_document_id, 'kept' => true];
        }

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
