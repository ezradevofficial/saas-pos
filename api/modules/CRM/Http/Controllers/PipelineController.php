<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\Setup\PipelineRequest;
use Modules\CRM\Http\Requests\Setup\SetupListRequest;
use Modules\CRM\Http\Requests\Setup\StageRequest;
use Modules\CRM\Http\Resources\SetupResource;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Stage;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Setup\Configuration;

/** CRM-02: pipelines and their stages. */
class PipelineController
{
    public function __construct(private readonly Configuration $configuration) {}

    public function index(SetupListRequest $request, CompanySetup $setup): JsonResponse
    {
        // CRM-02: a company named in the filter gets its default pipeline on first use.
        if ($request->filled('company')) {
            $setup->ensure(Company::query()->findOrFail($request->validated('company')));
        }

        $pipelines = $request->applyFilters(Pipeline::query())->with(['company', 'stages' => fn ($q) => $request->validated('status') === 'all' ? $q : $q->whereNull('archived_at')])
            ->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['data' => $pipelines->map(fn (Pipeline $pipeline) => SetupResource::pipeline($pipeline))->values()]);
    }

    public function store(PipelineRequest $request): JsonResponse
    {
        $pipeline = $this->configuration->createPipeline($request->company(), $request->validated());

        return $this->present($pipeline, 201);
    }

    public function update(PipelineRequest $request, Pipeline $crmPipeline): JsonResponse
    {
        return $this->present($this->configuration->updatePipeline($crmPipeline, $request->validated()));
    }

    public function archive(PipelineRequest $request, Pipeline $crmPipeline): JsonResponse
    {
        return $this->present($this->configuration->archivePipeline($crmPipeline, true));
    }

    public function restore(PipelineRequest $request, Pipeline $crmPipeline): JsonResponse
    {
        return $this->present($this->configuration->archivePipeline($crmPipeline, false));
    }

    public function addStage(PipelineRequest $request, Pipeline $crmPipeline): JsonResponse
    {
        $this->configuration->addStage($crmPipeline, $request->validated());

        return $this->present($crmPipeline, 201);
    }

    public function updateStage(StageRequest $request, Stage $crmStage): JsonResponse
    {
        return response()->json(['data' => SetupResource::stage($this->configuration->updateStage($crmStage, $request->validated()))]);
    }

    public function archiveStage(StageRequest $request, Stage $crmStage): JsonResponse
    {
        return response()->json(['data' => SetupResource::stage($this->configuration->archiveStage($crmStage))]);
    }

    private function present(Pipeline $pipeline, int $status = 200): JsonResponse
    {
        $pipeline = $pipeline->refresh()->load(['company', 'stages' => fn ($q) => $q->whereNull('archived_at')]);

        return response()->json(['data' => SetupResource::pipeline($pipeline)], $status);
    }
}
