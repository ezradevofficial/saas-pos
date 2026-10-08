<?php

namespace Tests\Support\Workflow;

/** Flow graphs for workflow tests (see FlowGraph for the format). */
final class Graphs
{
    public static function kes(int|string $minor): array
    {
        return ['amount_minor' => (string) $minor, 'currency' => 'KES'];
    }

    /**
     * The purchase requisition flow of the builder design (BoWorkflow):
     * check budget (entry rule), branch manager approval, "total over
     * KES 250,000?", CFO approval, create a draft order, notify, end.
     */
    public static function requisition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => 'Requisition submitted', 'position' => ['x' => 0, 'y' => 0]],
                ['id' => 'check_budget', 'type' => 'stage', 'name' => 'Check budget', 'mandatory' => true,
                    'entry' => ['field' => 'total', 'op' => 'lte', 'other' => 'budget'],
                    'exit_roles' => ['template:accountant', 'template:owner']],
                ['id' => 'branch_manager', 'type' => 'approval', 'name' => 'Branch manager approves',
                    'approval' => ['approver' => ['type' => 'branch_manager'], 'mode' => 'any'],
                    'exit_roles' => ['template:branch_manager', 'template:owner'],
                    'due' => ['amount' => 8, 'unit' => 'business_hours'],
                    'escalation' => ['after' => ['amount' => 8, 'unit' => 'business_hours'], 'to' => 'next_level']],
                ['id' => 'over_limit', 'type' => 'condition', 'name' => 'Total over KES 250,000?',
                    'condition' => ['field' => 'total', 'op' => 'gt', 'value' => self::kes(25000000)]],
                ['id' => 'cfo', 'type' => 'approval', 'name' => 'CFO approves',
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:accountant']],
                    'exit_roles' => ['template:accountant', 'template:owner'],
                    'due' => ['amount' => 3, 'unit' => 'business_days'],
                    'escalation' => ['final' => 'reject']],
                ['id' => 'create_order', 'type' => 'action', 'name' => 'Create draft purchase order',
                    'action' => 'create_document', 'config' => ['mapping' => 'order', 'on_cancel' => 'cancel']],
                ['id' => 'notify', 'type' => 'action', 'name' => 'Notify procurement', 'action' => 'notify', 'config' => ['to' => ['role:procurement_officer'], 'message' => 'Please prepare the order.']],
                ['id' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ['id' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'outcome' => 'rejected'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'check_budget'],
                ['from' => 'check_budget', 'to' => 'branch_manager'],
                ['from' => 'branch_manager', 'to' => 'over_limit', 'branch' => 'approved'],
                ['from' => 'branch_manager', 'to' => 'rejected', 'branch' => 'rejected'],
                ['from' => 'over_limit', 'to' => 'cfo', 'branch' => 'yes'],
                ['from' => 'over_limit', 'to' => 'create_order', 'branch' => 'no'],
                ['from' => 'cfo', 'to' => 'create_order', 'branch' => 'approved'],
                ['from' => 'cfo', 'to' => 'rejected', 'branch' => 'rejected'],
                ['from' => 'create_order', 'to' => 'notify'],
                ['from' => 'notify', 'to' => 'approved'],
            ],
        ];
    }

    /** start → each stage in turn → end. Stage options merge into each stage node. */
    public static function linear(array $stages, array $options = []): array
    {
        $nodes = [['id' => 'start', 'type' => 'start']];
        $edges = [];
        $previous = 'start';

        foreach ($stages as $id) {
            $nodes[] = ['id' => $id, 'type' => 'stage', 'name' => ucfirst(str_replace('_', ' ', $id)), ...($options[$id] ?? [])];
            $edges[] = ['from' => $previous, 'to' => $id];
            $previous = $id;
        }

        $nodes[] = ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'];
        $edges[] = ['from' => $previous, 'to' => 'end'];

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** start → prepare → split → (it, payroll) → join(mode) → close → end. */
    public static function parallel(string $mode = 'all'): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'prepare', 'type' => 'stage', 'name' => 'Prepare'],
                ['id' => 'split', 'type' => 'parallel', 'name' => 'At the same time'],
                ['id' => 'it', 'type' => 'stage', 'name' => 'IT setup'],
                ['id' => 'payroll', 'type' => 'stage', 'name' => 'Payroll setup'],
                ['id' => 'join', 'type' => 'join', 'split' => 'split', 'mode' => $mode],
                ['id' => 'close', 'type' => 'stage', 'name' => 'Close'],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'completed'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'prepare'],
                ['from' => 'prepare', 'to' => 'split'],
                ['from' => 'split', 'to' => 'it'],
                ['from' => 'split', 'to' => 'payroll'],
                ['from' => 'it', 'to' => 'join'],
                ['from' => 'payroll', 'to' => 'join'],
                ['from' => 'join', 'to' => 'close'],
                ['from' => 'close', 'to' => 'end'],
            ],
        ];
    }
}
