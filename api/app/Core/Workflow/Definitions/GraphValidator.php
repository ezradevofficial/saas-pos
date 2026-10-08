<?php

namespace App\Core\Workflow\Definitions;

use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Handlers\ActionHandlers;
use App\Core\Workflow\Handlers\ApprovalHandler;
use App\Core\Workflow\Runtime\StageTimers;

/**
 * Checks a flow graph (see FlowGraph) before it is saved and before it is
 * published (spec 6.4 "validation before publishing"; WF-03..WF-09).
 *
 * structural(): problems that make a graph unreadable (bad nodes or edges);
 * a draft with any of them is not saved.
 * validate(): those plus everything that would make a document stick or
 * misroute: no or several starts, no end, unreachable nodes, dead ends
 * (no end reachable), cycles, wrong outgoing edges per node type, missing
 * names, invalid entry/exit/branch conditions or conditions on unknown
 * fields, unknown roles, bad time limits, joins without their split (and
 * parallel branches that leave before the join), unknown actions or
 * next-document mappings, the approval handler's own checks, approvals
 * without a `rejected` path (H2), inside a parallel block, or whose
 * rejection can still end `approved` (H1). A draft
 * with any of them is not published.
 *
 * Each problem: `code`, `message` (translated), `node` (id or null).
 */
class GraphValidator
{
    public const MAX_NODES = 200;

    public const MAX_EDGES = 400;

    public const ID = '/^[A-Za-z0-9_-]{1,64}$/';

    public const DUE_UNITS = ['business_hours', 'business_days', 'hours', 'days'];

    public const JOIN_MODES = ['all', 'any'];

    public const MAX_STAGE_REMINDERS = 5;

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly ActionHandlers $actions,
        private readonly ApprovalHandler $approvals,
        private readonly RoleRefs $roles,
    ) {}

    /** @return list<array{code: string, message: string, node: ?string}> */
    public function structural(array $graph): array
    {
        $problems = [];
        $add = function (string $code, ?string $node = null, array $params = []) use (&$problems) {
            $problems[] = $this->problem($code, $node, $params);
        };

        $nodes = $graph['nodes'] ?? null;
        $edges = $graph['edges'] ?? null;

        if (! is_array($nodes) || ! array_is_list($nodes) || ! is_array($edges) || ! array_is_list($edges)) {
            $add('invalid_graph');

            return $problems;
        }

        if (count($nodes) > self::MAX_NODES || count($edges) > self::MAX_EDGES) {
            $add('too_large', null, ['nodes' => self::MAX_NODES, 'edges' => self::MAX_EDGES]);

            return $problems;
        }

        $ids = [];

        foreach ($nodes as $i => $node) {
            $id = is_array($node) && is_string($node['id'] ?? null) ? $node['id'] : null;

            if ($id === null || preg_match(self::ID, $id) !== 1) {
                $add('invalid_node', null, ['index' => $i + 1]);

                continue;
            }

            if (isset($ids[$id])) {
                $add('duplicate_node', $id, ['node' => $id]);

                continue;
            }

            $ids[$id] = true;

            if (! in_array($node['type'] ?? null, FlowGraph::NODE_TYPES, true)) {
                $add('unknown_node_type', $id, ['node' => $id, 'type' => is_string($node['type'] ?? null) ? $node['type'] : '']);
            }

            if (isset($node['name']) && (! is_string($node['name']) || mb_strlen($node['name']) > 120)) {
                $add('invalid_name', $id, ['node' => $id]);
            }
        }

        foreach ($edges as $i => $edge) {
            $valid = is_array($edge)
                && is_string($edge['from'] ?? null) && isset($ids[$edge['from']])
                && is_string($edge['to'] ?? null) && isset($ids[$edge['to']])
                && (! isset($edge['branch']) || (is_string($edge['branch']) && preg_match(self::ID, $edge['branch']) === 1));

            if (! $valid) {
                $add('invalid_edge', null, ['index' => $i + 1]);
            }
        }

        return $problems;
    }

    /** @return list<array{code: string, message: string, node: ?string}> */
    public function validate(array $graph, DocumentType $type): array
    {
        $problems = $this->structural($graph);

        if ($problems !== []) {
            return $problems;
        }

        $flow = FlowGraph::fromArray($graph);
        $add = function (string $code, ?string $node = null, array $params = []) use (&$problems, $flow) {
            $params = $node === null ? $params : ['node' => $flow->name($node), ...$params];
            $problems[] = $this->problem($code, $node, $params);
        };

        $starts = $flow->nodesOfType('start');

        if ($starts === []) {
            $add('no_start');
        } elseif (count($starts) > 1) {
            $add('many_starts');
        }

        if ($flow->nodesOfType('end') === []) {
            $add('no_end');
        }

        foreach ($flow->nodes as $id => $node) {
            $this->checkEdges($flow, $id, $node, $add);
            $this->checkNode($flow, $id, $node, $type, $add);
        }

        if ($starts !== []) {
            $reachable = array_flip([$starts[0], ...$flow->reachableFrom($starts[0])]);

            foreach (array_keys($flow->nodes) as $id) {
                if (! isset($reachable[$id])) {
                    $add('unreachable', $id);
                }
            }
        }

        $cycle = $this->cycleAt($flow);

        if ($cycle !== null) {
            $add('cycle', $cycle);
        } else {
            $this->checkDeadEnds($flow, $add);
            $this->checkParallel($flow, $add);
            $this->checkRejections($flow, $add);
            array_push($problems, ...$type->validateFlow($flow));
        }

        return $problems;
    }

    /** @param callable(string, ?string, array): void $add */
    private function checkEdges(FlowGraph $flow, string $id, array $node, callable $add): void
    {
        $out = $flow->outgoing($id);
        $branches = array_map(fn (array $e) => $e['branch'], $out);
        $sorted = $branches;
        sort($sorted);

        $ok = match ($node['type']) {
            'start', 'stage', 'join', 'action' => $branches === [null],
            'end' => $out === [],
            'parallel' => count($out) >= 2 && ! in_array(true, array_map(fn ($b) => $b !== null, $branches), true)
                && count(array_unique(array_column($out, 'to'))) === count($out),
            'approval' => in_array('approved', $branches, true)
                && array_diff($branches, ['approved', 'rejected']) === []
                && count($branches) === count(array_unique($branches)),
            'condition' => $sorted === $this->expectedBranches($node),
            default => true,
        };

        if (! $ok) {
            $add('wrong_edges.'.$node['type'], $id);
        } elseif ($node['type'] === 'approval' && ! in_array('rejected', $branches, true)) {
            // H2: a rejection (or a timeout ending in `reject`) needs a path.
            $add('approval_without_rejected', $id);
        }

        if ($node['type'] === 'start' && $flow->incoming($id) !== []) {
            $add('start_incoming', $id);
        }
    }

    /** @return list<string> the branch labels a condition node needs, sorted */
    private function expectedBranches(array $node): array
    {
        if (isset($node['branches']) && is_array($node['branches'])) {
            $keys = array_map(fn ($b) => is_array($b) && is_string($b['key'] ?? null) ? $b['key'] : '', $node['branches']);
            $keys[] = 'else';
        } else {
            $keys = ['no', 'yes'];
        }

        sort($keys);

        return $keys;
    }

    /** @param callable(string, ?string, array): void $add */
    private function checkNode(FlowGraph $flow, string $id, array $node, DocumentType $type, callable $add): void
    {
        $fields = $type->fieldsByName();
        $named = is_string($node['name'] ?? null) && trim($node['name']) !== '';

        switch ($node['type']) {
            case 'stage':
            case 'approval':
                if (! $named) {
                    $add('missing_name', $id);
                }

                if (isset($node['mandatory']) && ! is_bool($node['mandatory'])) {
                    $add('invalid_property', $id, ['property' => 'mandatory']);
                }

                foreach (['entry', 'exit'] as $rule) {
                    $this->checkCondition($node[$rule] ?? null, $fields, $id, $add, $rule);
                }

                foreach (['enter_roles', 'exit_roles'] as $key) {
                    $refs = $node[$key] ?? [];

                    if (! is_array($refs) || ! array_is_list($refs)) {
                        $add('invalid_property', $id, ['property' => $key]);
                    } elseif (($unknown = $this->roles->unknown($refs)) !== []) {
                        $add('unknown_role', $id, ['roles' => implode(', ', $unknown)]);
                    }
                }

                $due = $node['due'] ?? null;

                if ($due !== null && ! (is_array($due) && is_int($due['amount'] ?? null) && $due['amount'] >= 1 && $due['amount'] <= 10000
                    && in_array($due['unit'] ?? null, self::DUE_UNITS, true))) {
                    $add('invalid_due', $id);
                }

                foreach (['reminders', 'escalation'] as $key) {
                    if (isset($node[$key]) && ! is_array($node[$key])) {
                        $add('invalid_property', $id, ['property' => $key]);
                    }
                }

                if ($node['type'] === 'stage') {
                    $this->checkStageTimers($node, $id, $add);
                }

                if ($node['type'] === 'approval') {
                    foreach ($this->approvals->validate($node, $type) as $message) {
                        $add('approval', $id, ['problem' => $message]);
                    }
                }

                break;

            case 'condition':
                if (! $named) {
                    $add('missing_name', $id);
                }

                if (isset($node['branches'])) {
                    $branches = $node['branches'];
                    $keys = [];

                    if (! is_array($branches) || ! array_is_list($branches) || $branches === []) {
                        $add('invalid_property', $id, ['property' => 'branches']);

                        break;
                    }

                    foreach ($branches as $branch) {
                        $key = is_array($branch) ? ($branch['key'] ?? null) : null;

                        if (! is_string($key) || preg_match(self::ID, $key) !== 1 || in_array($key, ['else', ...$keys], true)) {
                            $add('invalid_property', $id, ['property' => 'branches']);

                            continue;
                        }

                        $keys[] = $key;
                        $this->checkCondition($branch['condition'] ?? null, $fields, $id, $add, 'branch', required: true);
                    }
                } else {
                    $this->checkCondition($node['condition'] ?? null, $fields, $id, $add, 'condition', required: true);
                }

                break;

            case 'join':
                $split = $node['split'] ?? null;

                if (! is_string($split) || $flow->type($split) !== 'parallel') {
                    $add('join_without_split', $id);
                }

                if (! in_array($node['mode'] ?? 'all', self::JOIN_MODES, true)) {
                    $add('join_mode', $id);
                }

                break;

            case 'parallel':
                $joins = array_filter($flow->nodesOfType('join'), fn (string $join) => ($flow->node($join)['split'] ?? null) === $id);

                if (count($joins) !== 1) {
                    $add('split_without_join', $id);
                }

                break;

            case 'action':
                $handler = is_string($node['action'] ?? null) ? $this->actions->find($node['action']) : null;

                if ($handler === null) {
                    $add('unknown_action', $id, ['action' => is_string($node['action'] ?? null) ? $node['action'] : '']);

                    break;
                }

                $config = $node['config'] ?? [];

                if (! is_array($config)) {
                    $add('invalid_property', $id, ['property' => 'config']);

                    break;
                }

                foreach ($handler->validate($config, $type) as $message) {
                    $add('action', $id, ['problem' => $message]);
                }

                break;

            case 'end':
                if (isset($node['outcome']) && (! is_string($node['outcome']) || preg_match('/^[a-z][a-z0-9_]{0,49}$/', $node['outcome']) !== 1)) {
                    $add('invalid_property', $id, ['property' => 'outcome']);
                }

                break;
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  callable(string, ?string, array): void  $add
     */
    private function checkCondition(mixed $condition, array $fields, string $id, callable $add, string $rule, bool $required = false): void
    {
        if ($condition === null || $condition === []) {
            if ($required) {
                $add('missing_condition', $id);
            }

            return;
        }

        if (! is_array($condition)) {
            $add('invalid_property', $id, ['property' => $rule]);

            return;
        }

        foreach ($this->conditions->validate($condition, $fields) as $problem) {
            $add('condition', $id, [
                'rule' => __('workflow.validation.rules.'.$rule),
                'problem' => __('workflow.conditions.invalid.'.$problem['code'], $problem['params']),
            ]);
        }
    }

    /** A node on a cycle, or null (flows are acyclic; sending back is the return action, WF-11). */
    private function cycleAt(FlowGraph $flow): ?string
    {
        $state = [];

        $visit = function (string $id) use (&$visit, &$state, $flow): ?string {
            $state[$id] = 1;

            foreach ($flow->outgoing($id) as $edge) {
                $to = $edge['to'];

                if (($state[$to] ?? 0) === 1) {
                    return $to;
                }

                if (($state[$to] ?? 0) === 0 && ($found = $visit($to)) !== null) {
                    return $found;
                }
            }

            $state[$id] = 2;

            return null;
        };

        foreach (array_keys($flow->nodes) as $id) {
            if (($state[$id] ?? 0) === 0 && ($found = $visit($id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param callable(string, ?string, array): void $add */
    private function checkDeadEnds(FlowGraph $flow, callable $add): void
    {
        foreach (array_keys($flow->nodes) as $id) {
            if ($flow->type($id) === 'end') {
                continue;
            }

            $ends = array_filter($flow->reachableFrom($id), fn (string $to) => $flow->type($to) === 'end');

            if ($ends === []) {
                $add('dead_end', $id);
            }
        }
    }

    /**
     * Every branch of a parallel split reaches its join before any end, and
     * everything flowing into the join comes from inside the split (WF-06).
     *
     * @param  callable(string, ?string, array): void  $add
     */
    private function checkParallel(FlowGraph $flow, callable $add): void
    {
        foreach ($flow->nodesOfType('join') as $join) {
            $split = $flow->node($join)['split'] ?? null;

            if (! is_string($split) || $flow->type($split) !== 'parallel') {
                continue;
            }

            $inside = [];

            foreach ($flow->outgoing($split) as $edge) {
                $queue = [$edge['to']];
                $seen = [];

                while ($queue !== []) {
                    $current = array_shift($queue);

                    if ($current === $join || isset($seen[$current])) {
                        continue;
                    }

                    $seen[$current] = true;

                    if ($flow->type($current) === 'end') {
                        $add('branch_escapes', $split, ['join' => $flow->name($join)]);

                        continue 2;
                    }

                    array_push($queue, ...array_column($flow->outgoing($current), 'to'));
                }

                $inside += $seen;
            }

            // Owner decision: a join carries no outcome, so a rejection inside a
            // branch could still end `approved` after the join. Group decisions
            // use the approval's own `all` or `majority` mode instead.
            // Every node after the split and before the join, branches that escape included.
            $between = [];
            $queue = array_column($flow->outgoing($split), 'to');

            while ($queue !== []) {
                $current = array_shift($queue);

                if ($current === $join || isset($between[$current])) {
                    continue;
                }

                $between[$current] = true;
                array_push($queue, ...array_column($flow->outgoing($current), 'to'));
            }

            foreach (array_keys($between) as $id) {
                if ($flow->type($id) === 'approval') {
                    $add('approval_in_parallel', $id);
                }
            }

            foreach ($flow->incoming($join) as $edge) {
                if ($edge['from'] !== $split && ! isset($inside[$edge['from']])) {
                    $add('join_split_mismatch', $join);

                    break;
                }
            }
        }
    }

    /**
     * WF-09: a plain stage's reminders (a list of at most MAX_STAGE_REMINDERS
     * offsets) and escalation ({after?, to: role or user}; escalation only
     * notifies, so no `final` or `next_level`), as StageTimers reads them.
     *
     * @param  callable(string, ?string, array): void  $add
     */
    private function checkStageTimers(array $node, string $id, callable $add): void
    {
        $reminders = $node['reminders'] ?? null;

        if ($reminders !== null && (! is_array($reminders) || ! array_is_list($reminders) || count($reminders) > self::MAX_STAGE_REMINDERS
            || in_array(null, array_map(StageTimers::duration(...), $reminders), true))) {
            $add('stage_reminders', $id, ['max' => self::MAX_STAGE_REMINDERS]);
        }

        $escalation = $node['escalation'] ?? null;

        if ($escalation === null || $escalation === []) {
            return;
        }

        $to = is_array($escalation) ? ($escalation['to'] ?? null) : null;
        $valid = is_array($escalation)
            && StageTimers::escalationTo($escalation) !== null
            && array_diff(array_keys($escalation), ['after', 'to']) === []
            && (! isset($escalation['after']) || StageTimers::duration($escalation['after']) !== null)
            && (isset($escalation['after']) || isset($node['due']))
            && (($to['type'] ?? null) !== 'role' || $this->roles->unknown([$to['role']]) === []);

        if (! $valid) {
            $add('stage_escalation', $id);
        }
    }

    /**
     * H1: after an approval's `rejected` edge, no `approved` end may be
     * reached without another approval's `approved` edge on the way.
     *
     * @param  callable(string, ?string, array): void  $add
     */
    private function checkRejections(FlowGraph $flow, callable $add): void
    {
        foreach ($flow->nodesOfType('approval') as $approval) {
            $rejected = $flow->next($approval, 'rejected');

            if ($rejected === null) {
                continue;
            }

            foreach ($flow->reachableWithoutApproval([$rejected]) as $id) {
                if ($flow->type($id) === 'end' && ($flow->node($id)['outcome'] ?? null) === 'approved') {
                    $add('rejected_reaches_approved', $approval, ['end' => $flow->name($id)]);

                    break;
                }
            }
        }
    }

    /** @return array{code: string, message: string, node: ?string} */
    private function problem(string $code, ?string $node, array $params): array
    {
        return ['code' => $code, 'message' => __('workflow.validation.'.$code, $params), 'node' => $node];
    }
}
