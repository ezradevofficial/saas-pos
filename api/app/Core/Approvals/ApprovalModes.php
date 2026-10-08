<?php

namespace App\Core\Approvals;

/**
 * APR-01: when a step of a request is decided, from the decisions of its
 * approvers (`$total` counts every approver of the step, decided or not;
 * reassigned rows are replaced by their new one and not counted).
 *
 * - any: the first decision decides the step (an approval approves, a
 *   rejection rejects);
 * - all: every approver approves; one rejection rejects;
 * - majority: more than half approve; rejected as soon as more than half
 *   can no longer approve (2 of 3, 2 of 2, 3 of 4 ...).
 *
 * An escalated approver's decision decides the step alone (APR-05): the
 * service passes it as `decisive`.
 */
final class ApprovalModes
{
    /** @return 'approved'|'rejected'|null null while undecided */
    public static function outcome(string $mode, int $approved, int $rejected, int $total, ?string $decisive = null): ?string
    {
        if ($decisive !== null) {
            return $decisive;
        }

        $total = max($total, 1);

        return match ($mode) {
            'all' => $rejected > 0 ? 'rejected' : ($approved >= $total ? 'approved' : null),
            'majority' => $approved * 2 > $total ? 'approved' : (($total - $rejected) * 2 <= $total ? 'rejected' : null),
            default => $approved > 0 ? 'approved' : ($rejected > 0 ? 'rejected' : null),
        };
    }
}
