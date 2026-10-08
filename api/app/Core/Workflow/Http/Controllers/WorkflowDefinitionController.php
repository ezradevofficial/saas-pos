<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\MasterData\CompanyReach;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Definitions\FlowDefinitions;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Http\Requests\CopyWorkflowRequest;
use App\Core\Workflow\Http\Requests\ListWorkflowsRequest;
use App\Core\Workflow\Http\Requests\PublishWorkflowRequest;
use App\Core\Workflow\Http\Requests\RestoreDefaultRequest;
use App\Core\Workflow\Http\Requests\RollbackWorkflowRequest;
use App\Core\Workflow\Http\Requests\StoreWorkflowRequest;
use App\Core\Workflow\Http\Requests\TestWorkflowRequest;
use App\Core\Workflow\Http\Requests\UpdateDraftRequest;
use App\Core\Workflow\Http\Requests\ValidateWorkflowRequest;
use App\Core\Workflow\Http\Requests\WorkflowRequest;
use App\Core\Workflow\Http\Resources\WorkflowDefinitionResource;
use App\Core\Workflow\Http\Resources\WorkflowVersionResource;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Runtime\DryRun;
use App\Core\Workflow\WorkflowAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WF-02, WF-03, APR-09, spec 6.4: flows per document type and company,
 * their versions, validation, publishing, roll back, copying between
 * companies, restoring the default and testing with a sample. Every
 * change is audited by FlowDefinitions (`core.workflow.*`).
 */
class WorkflowDefinitionController
{
    public function __construct(
        private readonly FlowDefinitions $definitions,
        private readonly DocumentTypeRegistry $types,
    ) {}

    public function index(ListWorkflowsRequest $request, CompanyReach $reach, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = WorkflowDefinition::query()->with(['company', 'published', 'draft'])
            ->whereIn('document_type', $this->types->keys());
        $companies = $reach->companyIds($request->user(), WorkflowAccess::PERMISSIONS);

        // RBAC-04: the flows of companies the user reaches, and the one for every company.
        if ($companies !== null) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        if (($type = $request->validated('type')) !== null) {
            $query->where('document_type', $type);
        }

        if (($company = $request->validated('company')) !== null) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company));
        }

        $request->applySort($request->applySearch($query, ['document_type' => 'document_type']));

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return WorkflowDefinitionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $data = $request->validated();
        $company = ($data['company_id'] ?? null) === null ? null : Company::query()->findOrFail($data['company_id']);
        $definition = $this->definitions->create($this->types->get($data['document_type']), $company, $request->user());

        return $this->present($definition)->response()->setStatusCode(201);
    }

    public function show(WorkflowRequest $request, WorkflowDefinition $workflow): WorkflowDefinitionResource
    {
        return $this->present($workflow);
    }

    public function versions(WorkflowRequest $request, WorkflowDefinition $workflow): AnonymousResourceCollection
    {
        $versions = $workflow->versions()
            ->withCount(['documents as in_progress_count' => fn ($q) => $q->where('status', DocumentWorkflow::RUNNING)])
            ->orderByDesc('version')->get();

        return WorkflowVersionResource::collection($versions);
    }

    public function updateDraft(UpdateDraftRequest $request, WorkflowDefinition $workflow): JsonResponse
    {
        $draft = $this->definitions->saveDraft($workflow, $request->graph(), $request->user());

        return new JsonResponse([
            'data' => WorkflowVersionResource::make($draft)->withGraph()->resolve($request),
            'meta' => ['problems' => $this->definitions->problems($workflow, $draft->graph)],
        ]);
    }

    public function validateDraft(ValidateWorkflowRequest $request, WorkflowDefinition $workflow): JsonResponse
    {
        $problems = $this->definitions->problems($workflow, $request->graph());

        return new JsonResponse(['data' => ['valid' => $problems === [], 'problems' => $problems]]);
    }

    public function publish(PublishWorkflowRequest $request, WorkflowDefinition $workflow): WorkflowDefinitionResource
    {
        $this->definitions->publish($workflow, $request->user());

        return $this->present($workflow);
    }

    public function rollback(RollbackWorkflowRequest $request, WorkflowDefinition $workflow): WorkflowDefinitionResource
    {
        $this->definitions->rollback($workflow, (int) $request->validated('version'), $request->user());

        return $this->present($workflow);
    }

    public function copy(CopyWorkflowRequest $request, WorkflowDefinition $workflow): JsonResponse
    {
        $request->authorizeTarget();
        $target = $this->definitions->copyTo($workflow, $request->target(), $request->validated('from', 'published'), $request->user());

        return $this->present($target)->response()->setStatusCode(201);
    }

    public function restoreDefault(RestoreDefaultRequest $request, WorkflowDefinition $workflow): WorkflowDefinitionResource
    {
        $this->definitions->restoreDefault($workflow, $request->user());

        return $this->present($workflow);
    }

    public function test(TestWorkflowRequest $request, WorkflowDefinition $workflow, DryRun $dryRun): JsonResponse
    {
        $graph = $request->graph();

        if ($graph === null && ($number = $request->validated('version')) !== null) {
            $graph = $workflow->versions()->where('version', (int) $number)->value('graph')
                ?? throw new ApiException(422, 'version_not_found', __('workflow.errors.version_not_found'));
            $graph = is_string($graph) ? json_decode($graph, true) : $graph;
        }

        $graph ??= ($workflow->draft()->first() ?? $workflow->published()->first())?->graph
            ?? throw new ApiException(422, 'no_draft', __('workflow.errors.no_draft'));

        return new JsonResponse(['data' => $dryRun->run(
            $graph,
            $this->types->get($workflow->document_type),
            $request->values(),
            (array) $request->validated('outcomes', []),
        )]);
    }

    private function present(WorkflowDefinition $definition): WorkflowDefinitionResource
    {
        return WorkflowDefinitionResource::make($definition->fresh(['company', 'published', 'draft']))->withGraphs();
    }
}
