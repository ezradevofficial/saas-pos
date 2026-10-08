<?php

namespace App\Core\Workflow\Insights;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\DocumentWorkflowToken;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\WorkflowAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * WF-10: volumes and bottlenecks per stage. For each live flow (a type's
 * published version, per company or for every company) the user may see,
 * and each stage or approval of that live version:
 *
 * - now: documents waiting there now (active positions);
 * - entered / left: positions that entered, or left, in the period
 *   (leaving includes being returned or cancelled);
 * - median and 90th percentile time in stage of the positions that left
 *   in the period, in elapsed seconds (not business hours);
 * - overdue: positions waiting there now past their time limit (WF-09).
 *
 * Positions on earlier versions count at the live version's node of the
 * same id (documents stay on their version, APR-09). Only documents whose
 * flow the user may see count (RBAC-04: the type's view or act permission
 * at the document's scope, as on the status page); the stage with the
 * longest median (then 90th percentile) is the flow's bottleneck.
 */
class WorkflowInsights
{
    /** @var array<string, bool> */
    private array $seen = [];

    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly WorkflowAccess $access,
        private readonly CompanyReach $reach,
    ) {}

    /** The types whose flows (or documents) the user may look at here. */
    public function typesFor(User $user): array
    {
        $designer = $this->access->anywhere($user);

        return array_values(array_filter(
            $this->types->all(),
            fn (DocumentType $type) => $designer || $this->reach->anywhere($user, $this->documentPermissions($type)),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function compute(User $user, ?string $typeKey, ?string $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = CarbonImmutable::now();
        $types = collect($this->typesFor($user))
            ->filter(fn (DocumentType $type) => $typeKey === null || $type->key() === $typeKey)
            ->keyBy(fn (DocumentType $type) => $type->key());

        if ($types->isEmpty()) {
            return [];
        }

        $definitions = WorkflowDefinition::query()
            ->with(['company:id,name', 'published'])
            ->whereIn('document_type', $types->keys()->all())
            ->whereHas('published')
            ->when($companyId !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)))
            ->get()
            ->filter(fn (WorkflowDefinition $definition) => $this->access->sees($user, $definition)
                || $this->reach->reachesRecord($user, $definition->company_id, $this->documentPermissions($types[$definition->document_type])))
            ->sortBy(fn (WorkflowDefinition $definition) => [__($types[$definition->document_type]->label()), $definition->company_id === null ? '' : $definition->company?->name])
            ->values();

        return $definitions->map(fn (WorkflowDefinition $definition) => $this->flow(
            $user, $definition, $types[$definition->document_type], $companyId, $from, $to, $now,
        ))->all();
    }

    /** @return array<string, mixed> */
    private function flow(User $user, WorkflowDefinition $definition, DocumentType $type, ?string $companyId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $live = $definition->published;
        $graph = $live->flow();
        $nodes = $this->holdingNodes($graph);

        $tokens = DocumentWorkflowToken::query()
            ->join('document_workflows', 'document_workflows.id', '=', 'document_workflow_tokens.workflow_id')
            ->join('workflow_versions', 'workflow_versions.id', '=', 'document_workflows.version_id')
            ->where('workflow_versions.definition_id', $definition->id)
            ->whereIn('document_workflow_tokens.node_id', $nodes === [] ? [''] : $nodes)
            ->when($companyId !== null, fn ($q) => $q->where('document_workflows.company_id', $companyId))
            ->where(fn ($q) => $q->where('document_workflow_tokens.status', DocumentWorkflowToken::ACTIVE)
                ->orWhereBetween('document_workflow_tokens.entered_at', [$from, $to])
                ->orWhereBetween('document_workflow_tokens.left_at', [$from, $to]))
            ->get([
                'document_workflow_tokens.node_id', 'document_workflow_tokens.status', 'document_workflow_tokens.entered_at',
                'document_workflow_tokens.left_at', 'document_workflow_tokens.due_at',
                'document_workflows.document_id as document_id', 'document_workflows.company_id as document_company_id',
            ])
            ->filter(fn (DocumentWorkflowToken $token) => $this->seesDocument($user, $type, $token->document_id, $token->document_company_id))
            ->groupBy('node_id');

        $stages = array_map(function (string $nodeId) use ($graph, $tokens, $from, $to, $now) {
            /** @var Collection<int, DocumentWorkflowToken> $at */
            $at = $tokens->get($nodeId, collect());
            $active = $at->filter(fn ($token) => $token->status === DocumentWorkflowToken::ACTIVE);
            $left = $at->filter(fn ($token) => $token->left_at !== null && $token->left_at->betweenIncluded($from, $to));
            $durations = $left->map(fn ($token) => max(0, $token->left_at->getTimestamp() - $token->entered_at->getTimestamp()))->sort()->values()->all();

            return [
                'node_id' => $nodeId,
                'name' => $graph->name($nodeId),
                'kind' => $graph->type($nodeId),
                'now' => $active->count(),
                'entered' => $at->filter(fn ($token) => $token->entered_at !== null && $token->entered_at->betweenIncluded($from, $to))->count(),
                'left' => $left->count(),
                'median_seconds' => self::percentile($durations, 0.5),
                'p90_seconds' => self::percentile($durations, 0.9),
                'overdue' => $active->filter(fn ($token) => $token->due_at !== null && $token->due_at->lessThan($now))->count(),
                'slowest' => false,
            ];
        }, $nodes);

        $slowest = collect($stages)->filter(fn (array $stage) => $stage['median_seconds'] !== null)
            ->sortByDesc(fn (array $stage) => [$stage['median_seconds'], $stage['p90_seconds']])
            ->keys()->first();

        if ($slowest !== null && count($stages) > 1) {
            $stages[$slowest]['slowest'] = true;
        }

        return [
            'workflow_id' => $definition->id,
            'document_type' => $type->key(),
            'document_type_label' => __($type->label()),
            'company_id' => $definition->company_id,
            'company_name' => $definition->company_id === null ? null : $definition->company?->name,
            'version' => $live->version,
            'stages' => $stages,
        ];
    }

    /**
     * Stages and approvals in flow order: breadth first from the start,
     * then any the start does not reach, as the graph lists them.
     *
     * @return list<string>
     */
    private function holdingNodes(FlowGraph $graph): array
    {
        $order = [];
        $queue = array_filter([$graph->start()]);
        $visited = [];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $order[] = $id;

            foreach ($graph->outgoing($id) as $edge) {
                $queue[] = $edge['to'];
            }
        }

        $order = [...$order, ...array_keys($graph->nodes)];

        return array_values(array_unique(array_filter($order, fn (string $id) => in_array($graph->type($id), FlowGraph::HOLDING, true))));
    }

    /**
     * RBAC-04: a document's flow counts when the user may see it: holding
     * the type's view or act permission at its company covers every
     * document there; otherwise the document's own scope is asked.
     */
    private function seesDocument(User $user, DocumentType $type, string $documentId, ?string $companyId): bool
    {
        if ($companyId !== null) {
            $key = $type->key().'|company|'.$companyId;
            $this->seen[$key] ??= collect($this->documentPermissions($type))->contains(fn (string $permission) => $user->can($permission, Scope::company($companyId)));

            if ($this->seen[$key]) {
                return true;
            }
        }

        $key = $type->key().'|'.$documentId;

        return $this->seen[$key] ??= ($scope = $type->scope($documentId)) !== null && $this->access->seesDocument($user, $type, $scope);
    }

    /** @return list<string> */
    private function documentPermissions(DocumentType $type): array
    {
        return array_values(array_unique([$type->viewPermission(), $type->actPermission()]));
    }

    /**
     * The $p quantile of sorted values, interpolated between neighbours
     * (as PostgreSQL's percentile_cont); null without values.
     *
     * @param  list<int>  $sorted
     */
    public static function percentile(array $sorted, float $p): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $rank = $p * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return (int) round($sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low));
    }
}
