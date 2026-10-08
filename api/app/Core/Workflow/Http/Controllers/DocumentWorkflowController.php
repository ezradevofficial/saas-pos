<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Workflow\Http\Requests\CancelDocumentRequest;
use App\Core\Workflow\Http\Requests\DocumentWorkflowRequest;
use App\Core\Workflow\Http\Requests\MoveDocumentRequest;
use App\Core\Workflow\Http\Requests\ReturnDocumentRequest;
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
        return new JsonResponse(['data' => $this->engine->status($request->workflow()->fresh(), $request->user())]);
    }
}
