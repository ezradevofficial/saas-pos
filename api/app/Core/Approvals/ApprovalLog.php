<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAction;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use Carbon\CarbonImmutable;

/**
 * A request's history (approval_actions) and the audit log (AUD-01):
 * every approval, rejection, return, comment, request for information,
 * attachment, reassignment, escalation and automatic decision is written
 * to both. A delegate's decision is audited as theirs, on behalf of the
 * user who delegated (AuditContext `on_behalf_of`, APR-06).
 */
class ApprovalLog
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly AuditContext $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function action(
        ApprovalRequest $request,
        string $type,
        ?string $userId,
        ?string $comment = null,
        array $data = [],
        ?string $assignmentId = null,
        ?string $onBehalfOf = null,
    ): ApprovalAction {
        return ApprovalAction::create([
            'request_id' => $request->id,
            'assignment_id' => $assignmentId,
            'type' => $type,
            'user_id' => $userId,
            'on_behalf_of' => $onBehalfOf,
            'comment' => $comment,
            'data' => $data,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Audit `core.approval.<action>` on the request.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function audit(string $action, ApprovalRequest $request, ?array $before, ?array $after, ?string $onBehalfOf = null): void
    {
        $previous = $this->context->onBehalfOfUserId();

        if ($onBehalfOf !== null) {
            $this->context->setOnBehalfOfUserId($onBehalfOf);
        }

        try {
            $this->auditor->record('core.approval.'.$action, $request, $before, $after);
        } finally {
            $this->context->setOnBehalfOfUserId($previous);
        }
    }
}
