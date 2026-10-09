<?php

namespace App\Core\Layouts\Dashboards\Sources;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Workflow\Insights\WorkflowInsights;
use Carbon\CarbonImmutable;

/**
 * LAY-01, WF-09, WF-10: documents waiting past their time limit in the
 * live flows the reader may look at (only documents they reach count,
 * RBAC-04), as the workflow insights count them.
 */
class WorkflowsOverdue extends DashboardSource
{
    public function key(): string
    {
        return 'workflows.overdue';
    }

    public function widgets(): array
    {
        return [self::KPI, self::APPROVAL_COUNT];
    }

    /** Whoever the insights page opens for: a flow designer, or a holder of a document type's permissions. */
    public function available(User $user): bool
    {
        return app(WorkflowInsights::class)->typesFor($user) !== [];
    }

    public function data(User $user, array $params): array
    {
        $now = CarbonImmutable::now();
        $overdue = 0;

        foreach (app(WorkflowInsights::class)->compute($user, null, null, $now->subDay(), $now) as $flow) {
            foreach ($flow['stages'] ?? [] as $stage) {
                $overdue += (int) $stage['overdue'];
            }
        }

        return ['kind' => 'number', 'value' => $overdue, 'to' => '/settings/workflows/insights'];
    }
}
