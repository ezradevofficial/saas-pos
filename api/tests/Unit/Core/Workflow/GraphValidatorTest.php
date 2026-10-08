<?php

namespace Tests\Unit\Core\Workflow;

use App\Core\Rbac\Models\Role;
use App\Core\Workflow\Definitions\GraphValidator;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * Spec 6.4 "validation before publishing" (WF-03..WF-09): unreadable
 * graphs, starts and ends, unreachable nodes, dead ends, loops, edges per
 * node type, names, conditions on unknown fields, roles, time limits,
 * joins and splits, actions and next-document mappings. The tenant's
 * roles are read under its row-level security.
 */
class GraphValidatorTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    /** @return list<string> */
    private function codes(array $graph): array
    {
        return $this->inTenant(fn () => array_column(
            app(GraphValidator::class)->validate($graph, app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)),
            'code',
        ));
    }

    /** @param callable(array): array $change */
    private function changed(array $graph, callable $change): array
    {
        return $change($graph);
    }

    private function node(array &$graph, string $id): array
    {
        foreach ($graph['nodes'] as $i => $node) {
            if ($node['id'] === $id) {
                return [$i, $node];
            }
        }

        $this->fail("No node {$id}");
    }

    public function test_the_design_flow_and_the_shapes_used_in_tests_are_valid(): void
    {
        $this->assertSame([], $this->codes(Graphs::requisition()));
        $this->assertSame([], $this->codes(Graphs::linear(['a', 'b'])));
        $this->assertSame([], $this->codes(Graphs::parallel('all')));
        $this->assertSame([], $this->codes(Graphs::parallel('any')));
    }

    public function test_messages_are_translated_and_name_the_step(): void
    {
        $graph = Graphs::linear(['review']);
        $graph['nodes'][1]['name'] = '';

        $problems = $this->inTenant(fn () => app(GraphValidator::class)->validate($graph, app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)));

        $this->assertSame([['code' => 'missing_name', 'message' => 'Give “review” a name.', 'node' => 'review']], $problems);
    }

    public function test_unreadable_graphs_are_structural_problems(): void
    {
        $validator = app(GraphValidator::class);

        $this->assertSame(['invalid_graph'], array_column($validator->structural(['nodes' => 'x', 'edges' => []]), 'code'));
        $this->assertSame(['invalid_graph'], array_column($validator->structural([]), 'code'));
        $this->assertSame(['invalid_node'], array_column($validator->structural(['nodes' => [['type' => 'start']], 'edges' => []]), 'code'));
        $this->assertSame(['invalid_node'], array_column($validator->structural(['nodes' => [['id' => 'has space', 'type' => 'start']], 'edges' => []]), 'code'));
        $this->assertSame(['duplicate_node'], array_column($validator->structural(['nodes' => [['id' => 'a', 'type' => 'start'], ['id' => 'a', 'type' => 'end']], 'edges' => []]), 'code'));
        $this->assertSame(['unknown_node_type'], array_column($validator->structural(['nodes' => [['id' => 'a', 'type' => 'teleport']], 'edges' => []]), 'code'));
        $this->assertSame(['invalid_edge'], array_column($validator->structural(['nodes' => [['id' => 'a', 'type' => 'start']], 'edges' => [['from' => 'a', 'to' => 'b']]]), 'code'));
        $this->assertSame(['too_large'], array_column($validator->structural(['nodes' => array_fill(0, GraphValidator::MAX_NODES + 1, []), 'edges' => []]), 'code'));
        $this->assertSame([], $validator->structural(Graphs::requisition()));
    }

    public function test_starts_ends_reachability_dead_ends_and_loops(): void
    {
        $graph = Graphs::linear(['a']);

        $noStart = $graph;
        $noStart['nodes'][0]['type'] = 'stage';
        $noStart['nodes'][0]['name'] = 'Not a start';
        $this->assertContains('no_start', $this->codes($noStart));

        $twoStarts = $graph;
        $twoStarts['nodes'][] = ['id' => 'start2', 'type' => 'start'];
        $twoStarts['edges'][] = ['from' => 'start2', 'to' => 'a'];
        $this->assertContains('many_starts', $this->codes($twoStarts));

        $noEnd = ['nodes' => [['id' => 'start', 'type' => 'start'], ['id' => 'a', 'type' => 'stage', 'name' => 'A']], 'edges' => [['from' => 'start', 'to' => 'a']]];
        $this->assertContains('no_end', $this->codes($noEnd));
        $this->assertContains('wrong_edges.stage', $this->codes($noEnd));
        $this->assertContains('dead_end', $this->codes($noEnd));

        $orphan = $graph;
        $orphan['nodes'][] = ['id' => 'lost', 'type' => 'stage', 'name' => 'Lost'];
        $orphan['edges'][] = ['from' => 'lost', 'to' => 'end'];
        $this->assertSame(['unreachable'], $this->codes($orphan));

        $loop = Graphs::linear(['a', 'b']);
        $loop['nodes'][] = ['id' => 'again', 'type' => 'condition', 'name' => 'Again?', 'condition' => ['field' => 'urgent', 'op' => 'eq', 'value' => true]];
        $loop['edges'] = [
            ['from' => 'start', 'to' => 'a'], ['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'again'],
            ['from' => 'again', 'to' => 'a', 'branch' => 'yes'], ['from' => 'again', 'to' => 'end', 'branch' => 'no'],
        ];
        $this->assertContains('cycle', $this->codes($loop));

        $intoStart = $graph;
        $intoStart['nodes'][1]['type'] = 'condition';
        $intoStart['nodes'][1]['condition'] = ['field' => 'urgent', 'op' => 'eq', 'value' => true];
        $intoStart['edges'] = [['from' => 'start', 'to' => 'a'], ['from' => 'a', 'to' => 'start', 'branch' => 'yes'], ['from' => 'a', 'to' => 'end', 'branch' => 'no']];
        $this->assertContains('start_incoming', $this->codes($intoStart));
    }

    public function test_edges_per_node_type(): void
    {
        $graph = Graphs::requisition();

        $noRejected = $graph;
        $noRejected['edges'] = array_values(array_filter($graph['edges'], fn ($e) => ! ($e['from'] === 'branch_manager' && $e['branch'] === 'rejected')));
        $this->assertNotContains('wrong_edges.approval', $this->codes($noRejected));
        $this->assertContains('approval_without_rejected', $this->codes($noRejected), 'H2: a rejection needs a path');

        $noApproved = $graph;
        $noApproved['edges'] = array_values(array_filter($graph['edges'], fn ($e) => ! ($e['from'] === 'branch_manager' && ($e['branch'] ?? null) === 'approved')));
        $this->assertContains('wrong_edges.approval', $this->codes($noApproved));

        $missingNo = $graph;
        $missingNo['edges'] = array_values(array_filter($graph['edges'], fn ($e) => ! ($e['from'] === 'over_limit' && $e['branch'] === 'no')));
        $this->assertContains('wrong_edges.condition', $this->codes($missingNo));

        $stageFork = Graphs::linear(['a', 'b']);
        $stageFork['edges'][] = ['from' => 'a', 'to' => 'end'];
        $this->assertContains('wrong_edges.stage', $this->codes($stageFork));

        $labelled = Graphs::linear(['a']);
        $labelled['edges'][0]['branch'] = 'yes';
        $this->assertContains('wrong_edges.start', $this->codes($labelled));

        $afterEnd = Graphs::linear(['a']);
        $afterEnd['nodes'][] = ['id' => 'b', 'type' => 'stage', 'name' => 'B'];
        $afterEnd['nodes'][] = ['id' => 'end2', 'type' => 'end'];
        $afterEnd['edges'][] = ['from' => 'end', 'to' => 'b'];
        $afterEnd['edges'][] = ['from' => 'b', 'to' => 'end2'];
        $this->assertContains('wrong_edges.end', $this->codes($afterEnd));
    }

    public function test_labelled_branches_need_an_edge_per_key_and_else(): void
    {
        $graph = Graphs::linear(['small', 'large']);
        $graph['nodes'][] = ['id' => 'size', 'type' => 'condition', 'name' => 'Size', 'branches' => [
            ['key' => 'large', 'condition' => ['field' => 'total', 'op' => 'gte', 'value' => Graphs::kes(100000)]],
        ]];
        $graph['edges'] = [
            ['from' => 'start', 'to' => 'size'],
            ['from' => 'size', 'to' => 'large', 'branch' => 'large'],
            ['from' => 'size', 'to' => 'small', 'branch' => 'else'],
            ['from' => 'large', 'to' => 'end'],
            ['from' => 'small', 'to' => 'end'],
        ];
        $this->assertSame([], $this->codes($graph));

        $noElse = $graph;
        array_splice($noElse['edges'], 2, 1);
        $this->assertContains('wrong_edges.condition', $this->codes($noElse));

        $reserved = $graph;
        $reserved['nodes'][4]['branches'][0]['key'] = 'else';
        $this->assertContains('invalid_property', $this->codes($reserved));
    }

    public function test_stage_properties(): void
    {
        $graph = Graphs::linear(['a'], ['a' => [
            'entry' => ['field' => 'colour', 'op' => 'eq', 'value' => 'red'],
            'exit' => ['field' => 'total', 'op' => 'contains', 'value' => 'x'],
            'enter_roles' => ['template:no_such_template', '01a1d0c0-0000-7000-8000-000000000000'],
            'exit_roles' => 'template:owner',
            'due' => ['amount' => 0, 'unit' => 'fortnights'],
            'mandatory' => 'yes',
            'reminders' => 'often',
        ]]);

        $problems = $this->inTenant(fn () => app(GraphValidator::class)->validate($graph, app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)));
        $codes = array_column($problems, 'code');

        $this->assertSame(2, count(array_keys($codes, 'condition')), 'the entry rule (unknown field) and exit rule (operator) are reported');
        $this->assertContains('unknown_role', $codes);
        $this->assertContains('invalid_due', $codes);
        $this->assertSame(3, count(array_keys($codes, 'invalid_property')), 'exit_roles, mandatory, reminders');

        $condition = collect($problems)->firstWhere('code', 'condition');
        $this->assertSame('“A”, entry rule: the field “colour” doesn’t exist on this document type.', $condition['message']);
        $this->assertStringContainsString('template:no_such_template', collect($problems)->firstWhere('code', 'unknown_role')['message']);
    }

    public function test_roles_of_another_tenant_or_archived_are_unknown(): void
    {
        $other = $this->otherTenant();
        $theirs = $this->asTenant($other['user']->tenant_id, fn () => Role::query()->value('id'));
        $archived = $this->inTenant(function () {
            $role = $this->role('Temporary', ['core.party.view']);
            $role->archive();

            return $role->id;
        });
        $ours = $this->roles->get('accountant')->id;

        $graph = Graphs::linear(['a'], ['a' => ['exit_roles' => [$ours, $theirs, $archived]]]);
        $problems = $this->inTenant(fn () => app(GraphValidator::class)->validate($graph, app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)));

        $this->assertSame(['unknown_role'], array_column($problems, 'code'));
        $this->assertStringContainsString($theirs, $problems[0]['message']);
        $this->assertStringContainsString($archived, $problems[0]['message']);
        $this->assertStringNotContainsString($ours, $problems[0]['message']);
    }

    public function test_conditions_need_a_rule_on_known_fields(): void
    {
        $graph = Graphs::requisition();
        [$i] = $this->node($graph, 'over_limit');

        $missing = $graph;
        unset($missing['nodes'][$i]['condition']);
        $this->assertContains('missing_condition', $this->codes($missing));

        $unknown = $graph;
        $unknown['nodes'][$i]['condition'] = ['field' => 'department', 'op' => 'eq', 'value' => 'HR'];
        $this->assertContains('condition', $this->codes($unknown));
    }

    public function test_joins_and_splits_must_match(): void
    {
        $graph = Graphs::parallel();

        $noSplit = $graph;
        $noSplit['nodes'][5]['split'] = 'prepare';
        $codes = $this->codes($noSplit);
        $this->assertContains('join_without_split', $codes);
        $this->assertContains('split_without_join', $codes);

        $badMode = $graph;
        $badMode['nodes'][5]['mode'] = 'most';
        $this->assertContains('join_mode', $this->codes($badMode));

        // A branch that ends the flow before the join.
        $escape = $graph;
        $escape['nodes'][] = ['id' => 'check', 'type' => 'condition', 'name' => 'Check', 'condition' => ['field' => 'urgent', 'op' => 'eq', 'value' => true]];
        $escape['nodes'][] = ['id' => 'stop', 'type' => 'end', 'outcome' => 'rejected'];
        $escape['edges'] = array_values(array_filter($escape['edges'], fn ($e) => ! ($e['from'] === 'it')));
        $escape['edges'][] = ['from' => 'it', 'to' => 'check'];
        $escape['edges'][] = ['from' => 'check', 'to' => 'join', 'branch' => 'yes'];
        $escape['edges'][] = ['from' => 'check', 'to' => 'stop', 'branch' => 'no'];
        $this->assertContains('branch_escapes', $this->codes($escape));

        // Something from outside the split flowing into the join.
        $outside = $graph;
        $outside['nodes'][1] = ['id' => 'prepare', 'type' => 'condition', 'name' => 'Skip?', 'condition' => ['field' => 'urgent', 'op' => 'eq', 'value' => true]];
        $outside['edges'] = array_values(array_filter($outside['edges'], fn ($e) => $e['from'] !== 'prepare'));
        $outside['edges'][] = ['from' => 'prepare', 'to' => 'split', 'branch' => 'no'];
        $outside['edges'][] = ['from' => 'prepare', 'to' => 'join', 'branch' => 'yes'];
        $this->assertContains('join_split_mismatch', $this->codes($outside));

        $oneBranch = $graph;
        $oneBranch['edges'] = array_values(array_filter($oneBranch['edges'], fn ($e) => ! ($e['from'] === 'split' && $e['to'] === 'payroll')));
        $this->assertContains('wrong_edges.parallel', $this->codes($oneBranch));
    }

    public function test_actions_and_next_document_mappings(): void
    {
        $graph = Graphs::requisition();
        [$i] = $this->node($graph, 'create_order');

        $unknownAction = $graph;
        $unknownAction['nodes'][$i]['action'] = 'launch_rocket';
        $this->assertContains('unknown_action', $this->codes($unknownAction));

        $unknownMapping = $graph;
        $unknownMapping['nodes'][$i]['config']['mapping'] = 'invoice';
        $this->assertContains('action', $this->codes($unknownMapping));

        $badCancel = $graph;
        $badCancel['nodes'][$i]['config']['on_cancel'] = 'delete';
        $this->assertContains('action', $this->codes($badCancel));

        $badApproval = $graph;
        [$j] = $this->node($graph, 'cfo');
        $badApproval['nodes'][$j]['approval'] = ['a', 'list'];
        $this->assertContains('approval', $this->codes($badApproval));

        $badOutcome = $graph;
        [$k] = $this->node($graph, 'approved');
        $badOutcome['nodes'][$k]['outcome'] = 'Approved!';
        $this->assertContains('invalid_property', $this->codes($badOutcome));
    }

    /** start → approve → (approved: end approved, rejected: ...$rejectedPath). */
    private function rejectionGraph(array $nodes, array $edges): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approves', 'approval' => ['approver' => ['type' => 'branch_manager']]],
                ['id' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ['id' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'outcome' => 'rejected'],
                ...$nodes,
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ...$edges,
            ],
        ];
    }

    public function test_a_rejection_cannot_reach_an_approved_end_without_another_approval(): void
    {
        // H1: rejected leads straight to the approved end.
        $direct = $this->rejectionGraph([], [['from' => 'approve', 'to' => 'approved', 'branch' => 'rejected']]);
        $this->assertContains('rejected_reaches_approved', $this->codes($direct));

        // Through a stage and a condition, both branches ending approved.
        $via = $this->rejectionGraph([
            ['id' => 'rework', 'type' => 'stage', 'name' => 'Rework'],
            ['id' => 'urgent', 'type' => 'condition', 'name' => 'Urgent?', 'condition' => ['field' => 'urgent', 'op' => 'eq', 'value' => true]],
        ], [
            ['from' => 'approve', 'to' => 'rework', 'branch' => 'rejected'],
            ['from' => 'rework', 'to' => 'urgent'],
            ['from' => 'urgent', 'to' => 'rejected', 'branch' => 'yes'],
            ['from' => 'urgent', 'to' => 'approved', 'branch' => 'no'],
        ]);
        $problems = $this->inTenant(fn () => app(GraphValidator::class)->validate($via, app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)));
        $this->assertContains(['code' => 'rejected_reaches_approved', 'message' => 'After “Manager approves” rejects, the workflow can still reach “Approved”, which ends approved. Lead the rejection to an end that isn’t approved, or through another approval.', 'node' => 'approve'], $problems);

        // An optional second approval can be skipped along `approved`: still refused.
        $optional = $this->rejectionGraph([
            ['id' => 'director', 'type' => 'approval', 'name' => 'Director', 'mandatory' => false,
                'entry' => ['field' => 'urgent', 'op' => 'eq', 'value' => true], 'approval' => ['approver' => ['type' => 'branch_manager']]],
        ], [
            ['from' => 'approve', 'to' => 'director', 'branch' => 'rejected'],
            ['from' => 'director', 'to' => 'approved', 'branch' => 'approved'],
            ['from' => 'director', 'to' => 'rejected', 'branch' => 'rejected'],
        ]);
        $this->assertContains('rejected_reaches_approved', $this->codes($optional));

        // A second (mandatory) approval re-decides: allowed.
        $second = $optional;
        $second['nodes'][4] = ['id' => 'director', 'type' => 'approval', 'name' => 'Director', 'approval' => ['approver' => ['type' => 'branch_manager']]];
        $this->assertSame([], $this->codes($second));

        // Rejected to a rejected end: fine.
        $this->assertSame([], $this->codes($this->rejectionGraph([], [['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected']])));
    }

    public function test_approvals_are_refused_inside_parallel_branches(): void
    {
        $graph = Graphs::parallel();
        $graph['nodes'][3] = ['id' => 'it', 'type' => 'approval', 'name' => 'IT approves', 'approval' => ['approver' => ['type' => 'branch_manager']]];
        $graph['nodes'][] = ['id' => 'refused', 'type' => 'end', 'outcome' => 'rejected'];
        $graph['edges'][] = ['from' => 'it', 'to' => 'refused', 'branch' => 'rejected'];

        $problems = $this->inTenant(fn () => app(GraphValidator::class)->validate($graph, app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)));

        $this->assertContains([
            'code' => 'approval_in_parallel',
            'message' => 'Approvals can’t run in parallel branches yet. Use the approval’s “all” or “majority” mode for a group decision.',
            'node' => 'it',
        ], $problems);

        // Before the split and after the join is fine.
        $outside = Graphs::parallel();
        $outside['nodes'][6] = ['id' => 'close', 'type' => 'approval', 'name' => 'Close', 'approval' => ['approver' => ['type' => 'branch_manager']]];
        $outside['nodes'][] = ['id' => 'refused', 'type' => 'end', 'outcome' => 'rejected'];
        $outside['edges'][] = ['from' => 'close', 'to' => 'refused', 'branch' => 'rejected'];
        $this->assertSame([], $this->codes($outside));
    }
}
