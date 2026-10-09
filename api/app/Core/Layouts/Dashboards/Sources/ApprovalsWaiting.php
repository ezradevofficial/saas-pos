<?php

namespace App\Core\Layouts\Dashboards\Sources;

use App\Core\Approvals\ApprovalInbox;
use App\Core\Approvals\Delegations;
use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;

/**
 * LAY-01, APR-04: how many approvals wait for the reader (their own and
 * those delegated to them), as the inbox counts them. Everyone has an
 * inbox, so no permission is needed; only the reader's items count.
 */
class ApprovalsWaiting extends DashboardSource
{
    public function key(): string
    {
        return 'approvals.waiting';
    }

    public function widgets(): array
    {
        return [self::APPROVAL_COUNT, self::KPI];
    }

    public function data(User $user, array $params): array
    {
        $count = app(ApprovalInbox::class)->query($user, ['status' => 'waiting', 'view' => 'mine'], app(Delegations::class)->to($user))->count();

        return ['kind' => 'number', 'value' => $count, 'to' => '/approvals'];
    }
}
