<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * The runtime of `approval` nodes (APR-01..APR-09). The engine binds
 * ManualApprovalHandler (an approval behaves as a stage completed by its
 * roles); the approvals service (phase 3, task 2 of the plan's task 3)
 * rebinds this interface in its service provider.
 *
 * Lifecycle: entered() when a document reaches the node; the handler
 * decides, then calls WorkflowEngine::completeNode($workflow, $tokenId,
 * 'approved' | 'rejected', $by) (the engine follows the matching edge);
 * left() when the position leaves the node for any reason (completed,
 * returned, cancelled, a parallel "any" join), so open requests close.
 * Every call runs inside the engine's transaction and tenant context.
 */
interface ApprovalHandler
{
    /**
     * Problems with the node's `approval` configuration, translated, for
     * the graph validator (empty when valid).
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public function validate(array $node, DocumentType $type): array;

    public function entered(ApprovalStep $step): void;

    /** @param string $why completed | returned | cancelled | joined */
    public function left(ApprovalStep $step, string $why): void;

    /** Whether people with the node's exit roles may complete it through the move endpoint (WF-08). */
    public function allowsManualCompletion(ApprovalStep $step): bool;

    /**
     * Who holds the step now (WF-10 "holder"), or null to show the node's
     * exit roles.
     *
     * @return array{roles: list<string>, users: list<string>}|null role and user ids
     */
    public function holders(ApprovalStep $step): ?array;
}
