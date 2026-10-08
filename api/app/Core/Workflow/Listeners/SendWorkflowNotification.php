<?php

namespace App\Core\Workflow\Listeners;

use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowNotificationRequested;
use App\Core\Workflow\Handlers\NotifyRecipients;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\WorkflowVersion;

/**
 * NOT-02 for flows: a `notify` node ran (after its move committed). In the
 * flow's tenant, resolve the node's recipients within the document's
 * scope and send `core.workflow.notify` through the Notifier, linking to
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

            $recipients = $this->recipients->resolve($event->config, $scope);

            if ($recipients === []) {
                return;
            }

            $flow = WorkflowVersion::query()->findOrFail($workflow->version_id)->flow();

            $this->notifier->send(new NotificationEvent(
                self::EVENT,
                $recipients,
                [
                    'document_type' => __($type->label()),
                    'step' => $flow->name($event->nodeId),
                    'message' => is_string($event->config['message'] ?? null) ? $event->config['message'] : '',
                ],
                self::link($event->documentType, $event->documentId),
            ));
        });
    }

    /** The web app's page for a document's flow. */
    public static function link(string $documentType, string $documentId): string
    {
        return '/document-workflows/'.rawurlencode($documentType).'/'.rawurlencode($documentId);
    }
}
