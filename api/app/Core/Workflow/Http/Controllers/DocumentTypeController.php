<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use App\Core\Workflow\DocumentTypes\NextDocument;
use App\Core\Workflow\Handlers\ActionHandlers;
use App\Core\Workflow\Http\Requests\WorkflowViewRequest;
use Illuminate\Http\JsonResponse;

/**
 * WF-01: the document types of the tenant's active modules, with their
 * fields (and the operators each field type allows), actions, documents
 * a flow may create, and the action handlers a flow may use. Drives the
 * builder's pickers.
 */
class DocumentTypeController
{
    public function index(WorkflowViewRequest $request, DocumentTypeRegistry $types, ActionHandlers $actions): JsonResponse
    {
        return new JsonResponse([
            'data' => array_values(array_map(fn (DocumentType $type) => [
                'key' => $type->key(),
                'label' => __($type->label()),
                'fields' => array_map(fn (FieldDefinition $field) => [
                    ...$field->toArray(),
                    'operators' => ConditionEvaluator::OPERATORS[$field->type],
                ], $type->fields()),
                'actions' => $type->actions(),
                'next_documents' => array_map(fn (NextDocument $next) => $next->toArray(), $type->nextDocuments()),
            ], $types->all())),
            'meta' => ['action_handlers' => $actions->keys()],
        ]);
    }
}
