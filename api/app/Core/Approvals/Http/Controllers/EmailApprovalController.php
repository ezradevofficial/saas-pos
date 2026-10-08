<?php

namespace App\Core\Approvals\Http\Controllers;

use App\Core\Approvals\EmailApprovals;
use App\Core\Approvals\Http\Requests\EmailApprovalRequest;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Http\JsonResponse;

/**
 * APR-08: the web app's confirm page for an emailed approve or reject
 * link (`/approvals/email/{token}`). GET answers what confirming would do
 * (changing nothing) or that the user must sign in; POST confirms.
 */
class EmailApprovalController
{
    public function __construct(private readonly EmailApprovals $emails) {}

    public function show(EmailApprovalRequest $request, string $token): JsonResponse
    {
        $opened = $this->emails->open($token);

        return new JsonResponse(['data' => [
            'status' => $opened['status'],
            'reason' => $opened['reason'],
            'message' => $opened['reason'] === null ? null : __('approvals.email.sign_in.'.$opened['reason']),
            'action' => $opened['action'],
            'approval_id' => $opened['approval_id'],
            'approval' => $opened['status'] === EmailApprovals::OK ? $this->summary($opened['request']) : null,
        ]]);
    }

    public function confirm(EmailApprovalRequest $request, string $token): JsonResponse
    {
        $decided = $this->emails->confirm($token, $request->validated('comment'), $request->ip(), $request->userAgent());

        return new JsonResponse(['data' => [
            'status' => 'done',
            'approval_id' => $decided->id,
            'approval_status' => $decided->status,
        ]]);
    }

    /** @return array<string, mixed> enough for the confirm page, nothing more */
    private function summary(ApprovalRequest $request): array
    {
        $type = app(DocumentTypeRegistry::class)->find($request->document_type);

        return [
            'id' => $request->id,
            'document_type_label' => $type === null ? $request->document_type : __($type->label()),
            'document_number' => $request->document_number,
            'document_title' => $request->document_title,
            'amount' => $request->amount(),
            'step' => $request->node_name,
            'requester' => $request->requester_id === null ? null : User::query()->whereKey($request->requester_id)->value('name'),
            'require_reason' => (bool) ($request->config['require_reason'] ?? false),
        ];
    }
}
