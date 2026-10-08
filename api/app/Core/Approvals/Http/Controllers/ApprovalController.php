<?php

namespace App\Core\Approvals\Http\Controllers;

use App\Core\Approvals\ApprovalAccess;
use App\Core\Approvals\ApprovalActions;
use App\Core\Approvals\ApprovalDecisions;
use App\Core\Approvals\ApprovalInbox;
use App\Core\Approvals\ApprovalPresenter;
use App\Core\Approvals\Delegations;
use App\Core\Approvals\Http\Requests\ApprovalItemRequest;
use App\Core\Approvals\Http\Requests\AttachToApprovalRequest;
use App\Core\Approvals\Http\Requests\BulkApproveRequest;
use App\Core\Approvals\Http\Requests\CommentApprovalRequest;
use App\Core\Approvals\Http\Requests\DecideApprovalRequest;
use App\Core\Approvals\Http\Requests\ListApprovalsRequest;
use App\Core\Approvals\Http\Requests\ReassignApprovalRequest;
use App\Core\Approvals\Http\Requests\ReturnApprovalRequest;
use App\Core\Approvals\Http\Resources\ApprovalItemResource;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * APR-03, APR-04, APR-06: the approvals inbox and the actions on a
 * request. Acting needs only being a pending approver (or their active
 * delegate); reassigning needs `core.approval.reassign` at the document's
 * place; anyone who cannot see a request gets 404. Every action answers
 * the request's detail as the actor now sees it.
 */
class ApprovalController
{
    public function __construct(
        private readonly ApprovalPresenter $presenter,
        private readonly ApprovalDecisions $decisions,
        private readonly ApprovalActions $actions,
        private readonly ApprovalAccess $access,
    ) {}

    public function index(ListApprovalsRequest $request, ApprovalInbox $inbox, Delegations $delegations, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $mine = $delegations->to($request->user());
        $request->attributes->set('approval_delegations', $mine);
        $query = $inbox->query($request->user(), [
            ...$request->safe()->only(['status', 'view', 'type', 'company', 'overdue', 'search']),
        ], $mine);

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ApprovalItemResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ApprovalItemRequest $request): JsonResponse
    {
        return $this->detail($request);
    }

    public function approve(DecideApprovalRequest $request): JsonResponse
    {
        $this->decisions->decide($request->approval(), $request->user(), ApprovalDecisions::APPROVE, $request->validated('comment'));

        return $this->detail($request);
    }

    public function reject(DecideApprovalRequest $request): JsonResponse
    {
        $this->decisions->decide($request->approval(), $request->user(), ApprovalDecisions::REJECT, $request->validated('comment'));

        return $this->detail($request);
    }

    public function return(ReturnApprovalRequest $request): JsonResponse
    {
        $this->actions->returnForChanges($request->approval(), $request->user(), $request->validated('node'), $request->validated('reason'));

        return $this->detail($request);
    }

    public function comment(CommentApprovalRequest $request): JsonResponse
    {
        $this->actions->comment($request->approval(), $request->user(), $request->validated('comment'));

        return $this->detail($request);
    }

    public function requestInfo(CommentApprovalRequest $request): JsonResponse
    {
        $this->actions->requestInfo($request->approval(), $request->user(), $request->validated('comment'));

        return $this->detail($request);
    }

    public function attach(AttachToApprovalRequest $request): JsonResponse
    {
        $this->actions->attach($request->approval(), $request->user(), $request->file('file'));

        return $this->detail($request, 201);
    }

    public function reassign(ReassignApprovalRequest $request): JsonResponse
    {
        $this->actions->reassign(
            $request->approval(), $request->user(), (string) $request->validated('from_user_id'), $request->validated('to_user_id'), $request->validated('reason'),
        );

        return $this->detail($request);
    }

    /**
     * Each id is approved on its own: not found (or not visible), not
     * allowed in bulk, or refused by the decision is reported, the others
     * go through.
     */
    public function bulkApprove(BulkApproveRequest $request): JsonResponse
    {
        $approved = [];
        $failed = [];

        foreach ($request->validated('ids') as $id) {
            $approval = ApprovalRequest::query()->find($id);

            if ($approval === null || ! $this->access->sees($request->user(), $approval)) {
                $failed[] = ['id' => $id, 'code' => 'not_found', 'message' => __('core.errors.not_found')];

                continue;
            }

            if (($approval->config['allow_bulk'] ?? true) !== true) {
                $failed[] = ['id' => $id, 'code' => 'bulk_not_allowed', 'message' => __('approvals.errors.bulk_not_allowed')];

                continue;
            }

            try {
                $this->decisions->decide($approval, $request->user(), ApprovalDecisions::APPROVE, $request->validated('comment'), 'bulk');
                $approved[] = $id;
            } catch (ApiException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                // L10: each item decides in its own transaction; one failure never turns the batch into a 500.
                Log::error('Bulk approval item failed', ['approval_id' => $id, 'error' => $e::class.': '.$e->getMessage()]);
                $failed[] = ['id' => $id, 'code' => 'error', 'message' => __('approvals.errors.bulk_item_failed')];
            }
        }

        return new JsonResponse(['data' => ['approved' => $approved, 'failed' => $failed]]);
    }

    private function detail(ApprovalItemRequest $request, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->detail($request->approval()->fresh(), $request->user())], $status);
    }
}
