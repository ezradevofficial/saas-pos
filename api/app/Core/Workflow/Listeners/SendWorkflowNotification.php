<?php

namespace App\Core\Workflow\Listeners;

use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowNotificationRequested;
use App\Core\Workflow\Handlers\NotifyRecipients;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\WorkflowAccess;

/**
 * NOT-02 for flows: a `notify` node ran (after its move committed). In the
 * flow's tenant, resolve the node's recipients within the document's
 * scope, keep those who may see the document (the history records who
 * was sent to and who was skipped) and send `core.workflow.notify` through the Notifier, linking to
 * the document's flow page (a relative app path only).
 */
class SendWorkflowNotification
{
    public const EVENT = 'core.workflow.notify';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DocumentTypeRegistry $types,
        private readonly NotifyRecipients $recipients,
        private readonly Notifier $notifier,
        private readonly WorkflowAccess $access,
    ) {}

    public function handle(WorkflowNotificationRequested $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            $type = $this->types->find($event->documentType);
            $scope = $type?->scope($event->documentId);
            $workflow = DocumentWorkflow::query()->find($event->workflowId);

            if ($type === null || $scope === null || $workflow === null) {
                return;
            }

            // Only people who may see the document hear about it (RBAC-04); the
            // others are skipped and the flow's history records them.
            $sent = [];
            $skipped = [];

            foreach (User::query()->whereKey($this->recipients->resolve($event->config, $scope))->get() as $user) {
                if ($this->access->seesDocument($user, $type, $scope)) {
                    $sent[] = $user->id;
                } else {
                    $skipped[] = $user->id;
                }
            }

            DocumentWorkflowEvent::create([
                'workflow_id' => $workflow->id,
                'type' => 'notified',
                'node_id' => $event->nodeId,
                'data' => ['sent' => $sent, 'skipped' => $skipped, 'skipped_reason' => $skipped === [] ? null : 'cannot_see_document'],
                'occurred_at' => now(),
            ]);

            if ($sent === []) {
                return;
            }

            $flow = WorkflowVersion::query()->findOrFail($workflow->version_id)->flow();

            $this->notifier->send(new NotificationEvent(
                self::EVENT,
                $sent,
                [
                    'document_type' => __($type->label()),
                    'step' => $flow->name($event->nodeId),
                    'message' => is_string($event->config['message'] ?? null) ? $event->config['message'] : '',
                ],
                self::link($event->documentType, $event->documentId),
            ));
        });
    }

    /**
     * The web app's page for a document: the type's own page when it has
     * one (LinksDocuments), else the generic flow status page (WF-10).
     */
    public static function link(string $documentType, string $documentId): string
    {
        $type = app(DocumentTypeRegistry::class)->find($documentType);

        return $type instanceof LinksDocuments ? $type->documentLink($documentId) : self::statusLink($documentType, $documentId);
    }

    /** WF-10: the generic page of a document's flow (status, steps, history). */
    public static function statusLink(string $documentType, string $documentId): string
    {
        return '/document-workflows/'.rawurlencode($documentType).'/'.rawurlencode($documentId);
    }
}
