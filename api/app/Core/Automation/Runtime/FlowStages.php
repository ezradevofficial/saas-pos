<?php

namespace App\Core\Automation\Runtime;

use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;

/**
 * The stages a document type's flows have (WF-02), for stage triggers and
 * "change stage" (AUTO-01, AUTO-03): the `stage` and `approval` nodes of
 * each flow's live version and its draft, by node id, with the flow's
 * company (null: every company). Stage triggers may name either kind;
 * "change stage" can only move `stage` nodes (approvals are decided by
 * approvers, APR-01).
 */
class FlowStages
{
    public const KINDS = ['stage', 'approval'];

    /**
     * @param  list<string>|null  $companyIds  the companies the reader reaches (null: all)
     * @return list<array{id: string, name: string, kind: string, company_id: ?string}>
     */
    public function of(string $documentType, ?array $companyIds = null): array
    {
        $flows = WorkflowDefinition::query()->with(['published', 'draft'])
            ->where('document_type', $documentType)
            ->when($companyIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $companyIds)))
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $stages = [];

        foreach ($flows as $flow) {
            foreach ([$flow->published, $flow->draft] as $version) {
                if (! $version instanceof WorkflowVersion) {
                    continue;
                }

                $graph = $version->flow();

                foreach (($version->graph['nodes'] ?? []) as $node) {
                    $id = $node['id'] ?? null;
                    $kind = $node['type'] ?? null;
                    $key = $flow->company_id.'|'.$id;

                    if (is_string($id) && in_array($kind, self::KINDS, true) && ! isset($stages[$key])) {
                        $stages[$key] = ['id' => $id, 'name' => $graph->name($id), 'kind' => $kind, 'company_id' => $flow->company_id];
                    }
                }
            }
        }

        return array_values($stages);
    }

    /**
     * Ids of the `stage` nodes "change stage" may move for a rule of
     * $companyId (null: every company): in that company's flow or the one
     * for every company; a rule for every company sees every flow.
     *
     * @return list<string>
     */
    public function movable(string $documentType, ?string $companyId): array
    {
        $stages = array_filter($this->of($documentType), fn (array $s) => $s['kind'] === 'stage'
            && ($companyId === null || $s['company_id'] === null || $s['company_id'] === $companyId));

        return array_values(array_unique(array_column($stages, 'id')));
    }
}
