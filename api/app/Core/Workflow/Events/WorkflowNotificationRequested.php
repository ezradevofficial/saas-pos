<?php

namespace App\Core\Workflow\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A flow's `notify` action ran (NotifyAction). The notifications service
 * listens and sends; until then nothing is sent. Dispatched after commit.
 */
class WorkflowNotificationRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, mixed> $config the node's configuration */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $workflowId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $nodeId,
        public readonly array $config,
    ) {}
}
