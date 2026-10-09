<?php

namespace App\Core\Layouts\Dashboards\Sources;

use App\Core\Approvals\ApprovalInbox;
use App\Core\Approvals\ApprovalPresenter;
use App\Core\Approvals\Delegations;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;

/**
 * LAY-01, APR-04: the approvals waiting for the reader, the oldest first,
 * as the inbox shows them (titles a document type hides from the reader
 * stay hidden, RBAC-05). `limit` rows, 3 to 10.
 */
class ApprovalsMine extends DashboardSource
{
    public function key(): string
    {
        return 'approvals.mine';
    }

    public function widgets(): array
    {
        return [self::LIST];
    }

    public function rules(): array
    {
        return ['limit' => ['sometimes', 'integer', 'between:3,10']];
    }

    public function data(User $user, array $params): array
    {
        $delegations = app(Delegations::class)->to($user);
        $presenter = app(ApprovalPresenter::class);
        $query = app(ApprovalInbox::class)->query($user, ['status' => 'waiting', 'view' => 'mine'], $delegations);
        $total = (clone $query)->count();
        $requests = $query->orderBy('received_at')->orderBy('id')->limit((int) ($params['limit'] ?? 5))->get();

        return [
            'total' => $total,
            'to' => '/approvals',
            'rows' => $requests->map(function (ApprovalRequest $request) use ($user, $delegations, $presenter) {
                $item = $presenter->item($request, $user, $delegations);
                $document = $item['document'];

                return [
                    'id' => $item['id'],
                    'title' => $document['title'] ?? $document['number'] ?? $document['type_label'],
                    'subtitle' => implode(' · ', array_filter([$document['type_label'], $document['number'], $item['step']['name']])),
                    'to' => '/approvals/'.$item['id'],
                    'at' => $item['received_at'],
                    'overdue' => $item['overdue'],
                ];
            })->values()->all(),
        ];
    }
}
