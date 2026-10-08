<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalAttachment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\Models\DocumentWorkflowToken;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * APR-03 and APR-06 actions besides approve and reject:
 *
 * - return for changes: an approver (or delegate) sends the document back
 *   to a stage or approval it passed, with a reason (the engine's return,
 *   WF-11); only within the approval's own branch of the flow;
 * - comment: anyone who sees a pending request (approvers, delegates, the
 *   requester, admins);
 * - request more information: an approver asks the requester; the request
 *   stays pending and the requester is notified;
 * - attach files: approvers, delegates and the requester, on a pending
 *   request (PDF, images, text, CSV, Word and Excel; 10 MB; 20 per request);
 * - reassign: `core.approval.reassign` at the document's place moves a
 *   pending assignment to another active user who can see the document
 *   (or holds a role covering it); never to the requester, someone already
 *   on the step or anyone who already decided a step (four eyes), and never
 *   by the requester.
 *
 * Everything is written to the request's history and audited.
 */
class ApprovalActions
{
    public const MAX_ATTACHMENTS = 20;

    public const DISK = 'media';

    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(
        private readonly ApprovalDecisions $decisions,
        private readonly ApprovalAccess $access,
        private readonly ApprovalRouting $routing,
        private readonly ApprovalLog $log,
        private readonly ApprovalNotices $notices,
        private readonly WorkflowEngine $engine,
        private readonly TenantContext $tenants,
        private readonly ScopeResolver $scopes,
    ) {}

    public function returnForChanges(ApprovalRequest $request, User $by, string $nodeId, string $reason): ApprovalRequest
    {
        return $this->decisions->transaction(function () use ($request, $by, $nodeId, $reason) {
            $request = $this->decisions->lockOrFail($request);
            [$assignment, $onBehalfOf] = $this->acting($request, $by);
            $this->decisions->assertNotRequester($request, $by, $onBehalfOf);

            $assignment->forceFill([
                'status' => ApprovalAssignment::RETURNED, 'decided_by' => $by->id, 'on_behalf_of' => $onBehalfOf,
                'decided_at' => CarbonImmutable::now(), 'comment' => $reason,
            ])->save();
            $this->decisions->expireEmailLinks($assignment);
            $this->log->action($request, 'returned', $by->id, $reason, ['node' => $nodeId], $assignment->id, $onBehalfOf);
            $this->log->audit('return', $request, ['status' => ApprovalRequest::PENDING], ['status' => ApprovalRequest::RETURNED, 'node' => $nodeId, 'reason' => $reason], $onBehalfOf);

            $request->forceFill(['status' => ApprovalRequest::RETURNED, 'decided_at' => CarbonImmutable::now(), 'escalate_at' => null, 'next_reminder_at' => null])->save();
            $this->engine->returnTo($request->workflow()->firstOrFail(), $by, $nodeId, $reason, $this->onlyOwnBranch($request, $nodeId));
            $this->notices->returned($request, $by, $reason);

            return $request->refresh();
        });
    }

    /** @return list<array{node_id: string, name: string}> stages and approvals the document passed, for "return for changes" */
    public function returnTargets(ApprovalRequest $request): array
    {
        $flow = WorkflowVersion::query()->find($request->version_id)?->flow();

        if ($flow === null) {
            return [];
        }

        return DocumentWorkflowToken::query()->where('workflow_id', $request->workflow_id)->where('status', DocumentWorkflowToken::DONE)
            ->orderBy('entered_at')->pluck('node_id')->unique()
            ->filter(fn (string $id) => in_array($flow->type($id), FlowGraph::HOLDING, true) && $id !== $request->node_id)
            ->map(fn (string $id) => ['node_id' => $id, 'name' => $flow->name($id)])
            ->values()->all();
    }

    public function comment(ApprovalRequest $request, User $by, string $comment): void
    {
        $this->decisions->transaction(function () use ($request, $by, $comment) {
            $request = $this->decisions->lockOrFail($request);

            if (! $request->isPending()) {
                throw new ApiException(422, 'approval_not_pending', __('approvals.errors.not_pending'));
            }

            $this->log->action($request, 'commented', $by->id, $comment);
            $this->log->audit('comment', $request, null, ['comment' => $comment]);
        });
    }

    public function requestInfo(ApprovalRequest $request, User $by, string $message): void
    {
        $this->decisions->transaction(function () use ($request, $by, $message) {
            $request = $this->decisions->lockOrFail($request);
            [$assignment, $onBehalfOf] = $this->acting($request, $by);

            if ($request->requester_id === null) {
                throw new ApiException(422, 'no_requester', __('approvals.errors.no_requester'));
            }

            $this->log->action($request, 'info_requested', $by->id, $message, [], $assignment->id, $onBehalfOf);
            $this->log->audit('request_info', $request, null, ['message' => $message], $onBehalfOf);
            $this->notices->infoRequested($request, $by, $message);
        });
    }

    public function reassign(ApprovalRequest $request, User $by, string $fromUserId, string $toUserId, ?string $reason): ApprovalRequest
    {
        return $this->decisions->transaction(function () use ($request, $by, $fromUserId, $toUserId, $reason) {
            $request = $this->decisions->lockOrFail($request);

            if (! $request->isPending()) {
                throw new ApiException(422, 'approval_not_pending', __('approvals.errors.not_pending'));
            }

            // The requester never moves their own request to someone of their choice.
            if (in_array($by->id, $this->routing->excluded($request), true)) {
                throw new ApiException(403, 'self_approval', __('approvals.errors.self_reassign'));
            }

            $from = $fromUserId === '' ? null : $request->assignments()->where('step', $request->step)
                ->where('status', ApprovalAssignment::PENDING)->where('user_id', $fromUserId)->first();

            // A blocked request has nobody to reassign from: the admin assigns it.
            if ($from === null && ! ($fromUserId === '' && $request->blocked_reason !== null)) {
                throw new ApiException(422, 'not_pending_approver', __('approvals.errors.not_pending_approver'), ['from_user_id' => [__('approvals.errors.not_pending_approver')]]);
            }

            // Four eyes: whoever already decided a step of this request cannot take another.
            if ($this->access->hasVoted($request, $toUserId)) {
                throw new ApiException(422, 'already_decided', __('approvals.errors.target_decided'), ['to_user_id' => [__('approvals.errors.target_decided')]]);
            }

            if ($this->routing->eligible($request, [$toUserId]) === []) {
                throw new ApiException(422, 'ineligible_approver', __('approvals.errors.ineligible_approver'), ['to_user_id' => [__('approvals.errors.ineligible_approver')]]);
            }

            if (! $this->mayHold($request, User::query()->findOrFail($toUserId))) {
                throw new ApiException(422, 'cannot_see_document', __('approvals.errors.target_out_of_scope'), ['to_user_id' => [__('approvals.errors.target_out_of_scope')]]);
            }

            if ($request->assignments()->where('step', $request->step)->where('status', ApprovalAssignment::PENDING)->where('user_id', $toUserId)->exists()) {
                throw new ApiException(422, 'already_approver', __('approvals.errors.already_approver'), ['to_user_id' => [__('approvals.errors.already_approver')]]);
            }

            if ($from !== null) {
                $from->forceFill(['status' => ApprovalAssignment::REASSIGNED, 'decided_by' => $by->id, 'decided_at' => CarbonImmutable::now(), 'comment' => $reason])->save();
                $this->decisions->expireEmailLinks($from);
            }

            [$created] = $this->routing->assign($request, [$toUserId], ApprovalAssignment::SOURCE_REASSIGNED, [
                'reassigned_from' => $from?->user_id, 'reassigned_by' => $by->id,
                // An escalated row keeps deciding alone once moved.
                ...($from?->source === ApprovalAssignment::SOURCE_ESCALATED ? ['source' => ApprovalAssignment::SOURCE_ESCALATED] : []),
            ]);

            if ($request->blocked_reason !== null) {
                $request->forceFill(['blocked_reason' => null])->save();
            }

            $this->log->action($request, 'reassigned', $by->id, $reason, ['from' => $from?->user_id, 'to' => $toUserId], $created->id);
            $this->log->audit('reassign', $request, ['user_id' => $from?->user_id], ['user_id' => $toUserId, 'reason' => $reason]);
            $this->notices->requested($request, [$toUserId]);

            return $request->refresh();
        });
    }

    public function attach(ApprovalRequest $request, User $by, UploadedFile $file): ApprovalAttachment
    {
        $mime = (string) $file->getMimeType();
        $extension = self::MIMES[$mime] ?? throw new ApiException(422, 'file_type', __('approvals.errors.file_type'), ['file' => [__('approvals.errors.file_type')]]);
        $this->assertMayAttach($request, $by);

        $path = sprintf('tenants/%s/approvals/%s/%s.%s', $this->tenants->require(), $request->id, Str::uuid7(), $extension);
        $disk = Storage::disk(self::DISK);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw new ApiException(500, 'file_not_stored', __('approvals.errors.file_not_stored'));
        }

        try {
            return $this->decisions->transaction(function () use ($request, $by, $file, $path, $mime) {
                $request = $this->decisions->lockOrFail($request);
                $this->assertMayAttach($request, $by);

                $attachment = ApprovalAttachment::create([
                    'request_id' => $request->id,
                    'uploaded_by' => $by->id,
                    'disk' => self::DISK,
                    'path' => $path,
                    'name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                    'mime' => $mime,
                    'size' => (int) $file->getSize(),
                ]);

                $this->log->action($request, 'attached', $by->id, null, ['attachment_id' => $attachment->id, 'name' => $attachment->name]);
                $this->log->audit('attach', $request, null, ['attachment' => $attachment->id, 'name' => $attachment->name, 'size' => $attachment->size]);

                return $attachment;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** The new approver may see the document, or holds a role at a place covering it (RBAC-04). */
    private function mayHold(ApprovalRequest $request, User $target): bool
    {
        return $this->access->seesDocument($target, $request)
            || $this->scopes->roleIds($target, ApprovalAccess::scope($request)->scope()) !== [];
    }

    private function assertMayAttach(ApprovalRequest $request, User $by): void
    {
        if (! $request->isPending()) {
            throw new ApiException(422, 'approval_not_pending', __('approvals.errors.not_pending'));
        }

        if ($request->requester_id !== $by->id && $this->access->acting($request, $by) === null) {
            throw new ApiException(403, 'forbidden', __('approvals.errors.attach_forbidden'));
        }

        if ($request->attachments()->count() >= self::MAX_ATTACHMENTS) {
            throw new ApiException(422, 'attachment_limit', __('approvals.errors.attachment_limit', ['max' => self::MAX_ATTACHMENTS]));
        }
    }

    /** @return array{0: ApprovalAssignment, 1: ?string} */
    private function acting(ApprovalRequest $request, User $by): array
    {
        if (! $request->isPending()) {
            throw new ApiException(422, 'approval_not_pending', __('approvals.errors.not_pending'));
        }

        return $this->access->acting($request, $by)
            ?? throw new ApiException(403, 'not_assignee', __('approvals.errors.not_assignee'));
    }

    /**
     * True when returning to $nodeId closes only this approval's position
     * (the target is in its branch and nothing else of that branch is
     * open): the approver may return without stage rights. Otherwise the
     * engine checks the returner's rights over every position closed.
     */
    private function onlyOwnBranch(ApprovalRequest $request, string $nodeId): bool
    {
        $target = DocumentWorkflowToken::query()->where('workflow_id', $request->workflow_id)->where('node_id', $nodeId)
            ->where('status', DocumentWorkflowToken::DONE)->latest('entered_at')->first();

        if ($target === null) {
            return false;
        }

        $branch = $target->groups ?? [];
        $affected = DocumentWorkflowToken::query()->where('workflow_id', $request->workflow_id)
            ->whereIn('status', [DocumentWorkflowToken::ACTIVE, DocumentWorkflowToken::WAITING])->get()
            ->filter(fn (DocumentWorkflowToken $t) => array_slice($t->groups ?? [], 0, count($branch)) === $branch);

        return $affected->count() === 1 && $affected->first()->id === $request->token_id;
    }
}
