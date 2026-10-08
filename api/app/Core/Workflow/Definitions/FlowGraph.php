<?php

namespace App\Core\Workflow\Definitions;

/**
 * A flow version's graph (WF-02..WF-09), as stored in
 * workflow_versions.graph:
 *
 *   {
 *     "nodes": [
 *       {"id": "start", "type": "start", "name": "Requisition submitted"},
 *       {"id": "check_budget", "type": "stage", "name": "Check budget", "mandatory": true,
 *        "entry": <condition>, "exit": <condition>,
 *        "enter_roles": ["template:accountant"], "exit_roles": ["<role uuid>"],
 *        "due": {"amount": 8, "unit": "business_hours"},
 *        "reminders": {...}, "escalation": {...}},
 *       {"id": "manager", "type": "approval", "name": "Branch manager approves", "approval": {...}, ...stage properties},
 *       {"id": "big", "type": "condition", "name": "Total over KES 250,000?", "condition": <condition>},
 *       {"id": "split", "type": "parallel"},
 *       {"id": "join", "type": "join", "split": "split", "mode": "all"},
 *       {"id": "po", "type": "action", "name": "Create draft purchase order", "action": "create_document",
 *        "config": {"mapping": "purchase_order", "on_cancel": "cancel"}},
 *       {"id": "end", "type": "end", "outcome": "approved"}
 *     ],
 *     "edges": [{"from": "start", "to": "check_budget"}, {"from": "big", "to": "cfo", "branch": "yes"}, ...]
 *   }
 *
 * Nodes may also carry `position` ({x, y}) for the builder; the engine
 * ignores it. Edge branches: `yes`/`no` from a condition (or a labelled
 * condition's branch keys and `else`), `approved`/`rejected` from an
 * approval (an unlabelled edge from an approval means `approved`), none
 * elsewhere. GraphValidator checks all of this before a version is
 * published; the runtime assumes a published graph is valid.
 */
final class FlowGraph
{
    public const NODE_TYPES = ['start', 'stage', 'approval', 'condition', 'parallel', 'join', 'action', 'end'];

    /** Nodes where a document waits for people (tokens stay there). */
    public const HOLDING = ['stage', 'approval'];

    /**
     * @param  array<string, array<string, mixed>>  $nodes  by id
     * @param  list<array{from: string, to: string, branch: ?string}>  $edges
     */
    private function __construct(
        public readonly array $nodes,
        public readonly array $edges,
    ) {}

    /** Lenient: malformed nodes and edges are dropped (GraphValidator reports them). */
    public static function fromArray(array $graph): self
    {
        $nodes = [];

        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (is_array($node) && is_string($node['id'] ?? null) && is_string($node['type'] ?? null) && ! isset($nodes[$node['id']])) {
                $nodes[$node['id']] = $node;
            }
        }

        $edges = [];

        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $edge) {
            if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)) {
                continue;
            }

            $branch = isset($edge['branch']) && is_string($edge['branch']) && $edge['branch'] !== '' ? $edge['branch'] : null;

            if ($branch === null && (($nodes[$edge['from']]['type'] ?? null) === 'approval')) {
                $branch = 'approved';
            }

            $edges[] = ['from' => $edge['from'], 'to' => $edge['to'], 'branch' => $branch];
        }

        return new self($nodes, $edges);
    }

    /** @return array<string, mixed>|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function type(string $id): ?string
    {
        return $this->nodes[$id]['type'] ?? null;
    }

    /** The node's display name, or its id. */
    public function name(string $id): string
    {
        $name = $this->nodes[$id]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $id;
    }

    /** @return list<string> */
    public function nodesOfType(string $type): array
    {
        return array_values(array_keys(array_filter($this->nodes, fn (array $node) => $node['type'] === $type)));
    }

    public function start(): ?string
    {
        return $this->nodesOfType('start')[0] ?? null;
    }

    /** @return list<array{from: string, to: string, branch: ?string}> */
    public function outgoing(string $id): array
    {
        return array_values(array_filter($this->edges, fn (array $edge) => $edge['from'] === $id));
    }

    /** @return list<array{from: string, to: string, branch: ?string}> */
    public function incoming(string $id): array
    {
        return array_values(array_filter($this->edges, fn (array $edge) => $edge['to'] === $id));
    }

    /** The target of the node's edge with $branch (null: the unlabelled edge). */
    public function next(string $id, ?string $branch = null): ?string
    {
        foreach ($this->outgoing($id) as $edge) {
            if ($edge['branch'] === $branch) {
                return $edge['to'];
            }
        }

        return null;
    }

    /** @return list<string> ids of every node reachable from $id, $id excluded */
    public function reachableFrom(string $id): array
    {
        $seen = [];
        $queue = array_column($this->outgoing($id), 'to');

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            array_push($queue, ...array_column($this->outgoing($current), 'to'));
        }

        return array_keys($seen);
    }

    /**
     * H1: ids of every node reachable from $from (included) without
     * passing an approval: out of a mandatory approval node only its
     * `rejected` edge is followed (its `approved` edge needs a decision).
     * An optional approval (`mandatory: false`) can be skipped along its
     * `approved` edge when its entry rule fails, so both its edges are
     * followed.
     *
     * @param  list<string>  $from
     * @return list<string>
     */
    public function reachableWithoutApproval(array $from): array
    {
        $seen = [];
        $queue = $from;

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current]) || ! isset($this->nodes[$current])) {
                continue;
            }

            $seen[$current] = true;
            $node = $this->nodes[$current];
            $gated = $node['type'] === 'approval' && ($node['mandatory'] ?? true) !== false;

            foreach ($this->outgoing($current) as $edge) {
                if (! $gated || $edge['branch'] === 'rejected') {
                    $queue[] = $edge['to'];
                }
            }
        }

        return array_keys($seen);
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'nodes' => array_values($this->nodes),
            'edges' => array_map(fn (array $e) => array_filter($e, fn ($v) => $v !== null), $this->edges),
        ];
    }
}
