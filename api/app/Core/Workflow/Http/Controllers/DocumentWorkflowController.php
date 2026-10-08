<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Http\Requests\CancelDocumentRequest;
use App\Core\Workflow\Http\Requests\DocumentWorkflowRequest;
use App\Core\Workflow\Http\Requests\MoveDocumentRequest;
use App\Core\Workflow\Http\Requests\ReturnDocumentRequest;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Http\JsonResponse;

/**
 * WF-04, WF-08, WF-10, WF-11: a document's flow: status and history; move
 * (complete the current stage), return to an earlier stage, cancel. The
 * engine checks stage roles (403) and entry/exit rules (422 with the
 * reasons) and audits every action (`core.workflow.*`).
 */
class DocumentWorkflowController
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function show(DocumentWorkflowRequest $request): JsonResponse
    {
        return $this->status($request);
    }

    public function move(MoveDocumentRequest $request): JsonResponse
    {
        $this->engine->move($request->workflow(), $request->user(), $request->validated('node'), $request->validated('outcome'));

        return $this->status($request);
    }

    public function return(ReturnDocumentRequest $request): JsonResponse
    {
        $this->engine->returnTo($request->workflow(), $request->user(), $request->validated('node'), $request->validated('reason'));

        return $this->status($request);
    }

    public function cancel(CancelDocumentRequest $request): JsonResponse
    {
        $this->engine->cancel($request->workflow(), $request->user(), $request->validated('reason'));

        return $this->status($request);
    }

    private function status(DocumentWorkflowRequest $request): JsonResponse
    {
        $workflow = $request->workflow()->fresh();

        return new JsonResponse(['data' => [
            ...$this->engine->status($workflow, $request->user()),
            'document' => $this->document($request->documentType(), $workflow, $request->user()),
        ]]);
    }

    /**
     * WF-10: what the status page names the document by: the type's label,
     * the summary's number and title (APR-04, without what
     * hiddenSummaryFields() hides from the viewer, RBAC-05), its company
     * (for times in the company's zone) and its own page when the type has
     * one (LinksDocuments).
     *
     * @return array{type_label: string, number: ?string, title: ?string, company_id: ?string, link: ?string}
     */
    private function document(DocumentType $type, DocumentWorkflow $workflow, User $viewer): array
    {
        $summary = $type->summary($workflow->document_id);
        $hidden = $type->hiddenSummaryFields($viewer);

        return [
            'type_label' => __($type->label()),
            'number' => is_string($summary['number'] ?? null) ? $summary['number'] : null,
            'title' => is_string($summary['title'] ?? null) && ! in_array('title', $hidden, true) ? $summary['title'] : null,
            'company_id' => $type->scope($workflow->document_id)?->companyId ?? $workflow->company_id,
            'link' => $type instanceof LinksDocuments ? $type->documentLink($workflow->document_id) : null,
        ];
    }
}
