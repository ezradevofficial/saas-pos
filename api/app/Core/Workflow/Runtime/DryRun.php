<?php

namespace App\Core\Workflow\Runtime;

use App\Core\Workflow\Conditions\ConditionDescriber;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\Conditions\ConditionResult;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\Definitions\GraphValidator;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Handlers\ActionHandlers;

/**
 * "Test with a sample" (spec 6.4, BoWorkflow design): walk a graph with
 * given field values and report the path a document would take and why,
 * without writing anything. Every stage is assumed completed by someone
 * allowed to; approvals are assumed approved unless `outcomes` says
 * otherwise (node id => approved | rejected). Entry and exit rules are
 * evaluated: a mandatory stage whose entry rule fails, or a stage whose
 * exit rule fails, stops that path (`blocked`). Parallel branches are all
 * walked; actions are described, not run.
 */
class DryRun
{
    /** Steps walked before giving up (graphs are acyclic; this guards bad input). */
    private const MAX_STEPS = 1000;

    public function __construct(
        private readonly GraphValidator $validator,
        private readonly ConditionEvaluator $conditions,
        private readonly ConditionDescriber $describer,
        private readonly ActionHandlers $actions,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $outcomes
     * @return array{valid: bool, problems: list<array<string, mixed>>, path: list<array<string, mixed>>, outcome: ?string, blocked: ?array<string, mixed>}
     */
    public function run(array $graph, DocumentType $type, array $values, array $outcomes = []): array
    {
        $problems = $this->validator->validate($graph, $type);

        if ($problems !== []) {
            return ['valid' => false, 'problems' => $problems, 'path' => [], 'outcome' => null, 'blocked' => null];
        }

        $flow = FlowGraph::fromArray($graph);
        $fields = $type->fieldsByName();
        $path = [];
        $outcome = null;
        $blocked = null;
        $arrivals = [];
        $closed = [];
        $steps = 0;
        // Each pending item: [node id, parallel groups].
        $queue = [[$flow->start(), []]];

        $evaluate = fn (mixed $condition): ConditionResult => $this->conditions->evaluate(is_array($condition) ? $condition : null, $values, $fields);
        $reasons = fn (ConditionResult $result): array => $this->describer->reasons($result, $fields);

        while ($queue !== [] && $blocked === null && $steps++ < self::MAX_STEPS) {
            [$id, $groups] = array_shift($queue);

            // An "any" join closed this branch.
            if (array_intersect($groups, $closed) !== []) {
                continue;
            }

            $node = $flow->node($id);
            $step = ['node_id' => $id, 'type' => $node['type'], 'name' => $flow->name($id)];

            switch ($node['type']) {
                case 'start':
                    $path[] = [...$step, 'result' => 'start', 'reasons' => []];
                    $queue[] = [$flow->next($id), $groups];
                    break;

                case 'stage':
                case 'approval':
                    $entry = $evaluate($node['entry'] ?? null);

                    if (! $entry->passed && ($node['mandatory'] ?? true) !== false) {
                        $blocked = ['node_id' => $id, 'name' => $flow->name($id), 'rule' => 'entry', 'reasons' => $reasons($entry)];
                        $path[] = [...$step, 'result' => 'blocked', 'reasons' => $reasons($entry)];
                        break;
                    }

                    if (! $entry->passed) {
                        $path[] = [...$step, 'result' => 'skipped', 'reasons' => $reasons($entry)];
                        $queue[] = [$flow->next($id, $node['type'] === 'approval' ? 'approved' : null), $groups];
                        break;
                    }

                    $decision = $node['type'] === 'approval' ? (($outcomes[$id] ?? 'approved') === 'rejected' ? 'rejected' : 'approved') : null;

                    if ($decision !== 'rejected') {
                        $exit = $evaluate($node['exit'] ?? null);

                        if (! $exit->passed) {
                            $blocked = ['node_id' => $id, 'name' => $flow->name($id), 'rule' => 'exit', 'reasons' => $reasons($exit)];
                            $path[] = [...$step, 'result' => 'blocked', 'reasons' => $reasons($exit)];
                            break;
                        }
                    }

                    $next = $flow->next($id, $decision);

                    if ($next === null) {
                        $blocked = ['node_id' => $id, 'name' => $flow->name($id), 'rule' => 'outcome', 'reasons' => [__('workflow.errors.no_rejected_path', ['stage' => $flow->name($id)])]];
                        $path[] = [...$step, 'result' => 'blocked', 'reasons' => $blocked['reasons']];
                        break;
                    }

                    $path[] = [...$step, 'result' => $decision ?? 'completed', 'reasons' => []];
                    $queue[] = [$next, $groups];
                    break;

                case 'condition':
                    [$branch, $result] = $this->branch($node, $evaluate);
                    $path[] = [...$step, 'result' => $branch, 'reasons' => $this->explain($result, $fields)];
                    $queue[] = [$flow->next($id, $branch), $groups];
                    break;

                case 'parallel':
                    $group = $id.'#'.$steps;
                    $path[] = [...$step, 'result' => 'split', 'reasons' => []];

                    foreach ($flow->outgoing($id) as $edge) {
                        $queue[] = [$edge['to'], [...$groups, $group]];
                    }

                    break;

                case 'join':
                    $group = end($groups);
                    $arrivals[$id.'|'.$group] = ($arrivals[$id.'|'.$group] ?? 0) + 1;
                    $needed = ($node['mode'] ?? 'all') === 'any' ? 1 : count($flow->outgoing((string) $node['split']));

                    if ($arrivals[$id.'|'.$group] === $needed) {
                        if ($needed === 1) {
                            $closed[] = $group;
                        }

                        $path[] = [...$step, 'result' => 'joined', 'reasons' => []];
                        $queue[] = [$flow->next($id), array_slice($groups, 0, -1)];
                    }

                    break;

                case 'action':
                    $handler = $this->actions->find((string) $node['action']);
                    $path[] = [...$step, 'result' => 'would_run', 'reasons' => [$handler->describe(is_array($node['config'] ?? null) ? $node['config'] : [], $type)]];
                    $queue[] = [$flow->next($id), $groups];
                    break;

                case 'end':
                    $outcome = is_string($node['outcome'] ?? null) ? $node['outcome'] : 'completed';
                    $path[] = [...$step, 'result' => 'end', 'reasons' => []];
                    break;
            }
        }

        return ['valid' => true, 'problems' => [], 'path' => $path, 'outcome' => $blocked === null ? $outcome : null, 'blocked' => $blocked];
    }

    /** @return array{0: string, 1: ConditionResult} */
    private function branch(array $node, callable $evaluate): array
    {
        if (isset($node['branches']) && is_array($node['branches'])) {
            $last = ConditionResult::pass();

            foreach ($node['branches'] as $branch) {
                $last = $evaluate($branch['condition'] ?? null);

                if ($last->passed) {
                    return [$branch['key'], $last];
                }
            }

            return ['else', $last];
        }

        $result = $evaluate($node['condition'] ?? null);

        return [$result->passed ? 'yes' : 'no', $result];
    }

    /** Why a condition went the way it did: its failures, or the comparisons that held. */
    private function explain(ConditionResult $result, array $fields): array
    {
        if (! $result->passed) {
            return $this->describer->reasons($result, $fields);
        }

        return array_values(array_map(
            fn ($check) => __('workflow.conditions.held', ['rule' => $this->describer->describe($check, $fields)]),
            array_filter($result->checks, fn ($check) => $check->passed),
        ));
    }
}
