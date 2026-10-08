<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Definitions\FlowDefinitions;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestOrderType;
use Tests\Support\Workflow\TestRequestType;

/**
 * Workflow tests: the test document types registered, an organisation
 * (BuildsOrganisation), and helpers to publish a flow and start a
 * document. Call setUpWorkflows() in setUp().
 */
trait BuildsWorkflows
{
    use BuildsOrganisation;

    protected function setUpWorkflows(): void
    {
        TestDocuments::reset();
        $types = app(DocumentTypeRegistry::class);
        $types->register(TestRequestType::class);
        $types->register(TestOrderType::class);
        $this->setUpOrganisation();
    }

    /** Publish $graph as the test type's flow in $company (null: every company). */
    protected function publishFlow(array $graph, ?Company $company = null, ?User $by = null): WorkflowVersion
    {
        return $this->inTenant(function () use ($graph, $company, $by) {
            $definitions = app(FlowDefinitions::class);
            $definition = WorkflowDefinition::query()->where('document_type', TestRequestType::KEY)->where('company_id', $company?->id)->first()
                ?? $definitions->create(app(DocumentTypeRegistry::class)->get(TestRequestType::KEY), $company, $by ?? $this->owner);
            $definitions->saveDraft($definition, $graph, $by ?? $this->owner);

            return $definitions->publish($definition, $by ?? $this->owner);
        });
    }

    /** A test request in the tenant, at branch A by default. */
    protected function document(array $values = [], ?DocumentScope $scope = null): string
    {
        return $this->inTenant(fn () => TestDocuments::create(
            TestRequestType::KEY,
            $values,
            $scope ?? new DocumentScope($this->acme->id, $this->branchA->id),
        ));
    }

    protected function start(string $documentId, ?User $by = null): DocumentWorkflow
    {
        return $this->inTenant(fn () => app(WorkflowEngine::class)->start(TestRequestType::KEY, $documentId, $by ?? $this->owner));
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    /** @return list<string> node ids of the active positions */
    protected function at(DocumentWorkflow $workflow): array
    {
        return $this->inTenant(fn () => $workflow->tokens()->where('status', 'active')->orderBy('node_id')->pluck('node_id')->all());
    }

    protected function workflowUrl(string $documentId, string $suffix = ''): string
    {
        return '/api/v1/document-workflows/'.TestRequestType::KEY.'/'.$documentId.$suffix;
    }
}
