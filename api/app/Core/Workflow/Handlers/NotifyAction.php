<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Events\WorkflowNotificationRequested;

/**
 * A `notify` action node (spec 6.4 palette "Notify"). Until the
 * notifications service is wired in (plan tasks 1 and 4), it records the
 * request and dispatches WorkflowNotificationRequested; a listener sends
 * it. Config is stored as given (e.g. {"to": "role:...", "template": "..."}).
 */
class NotifyAction implements ActionHandler
{
    public const KEY = 'notify';

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(array $config, DocumentType $type): array
    {
        return $config === [] || ! array_is_list($config) ? [] : [__('workflow.validation.action_config')];
    }

    public function run(ActionContext $context): array
    {
        WorkflowNotificationRequested::dispatch(
            $context->workflow->tenant_id,
            $context->workflow->id,
            $context->workflow->document_type,
            $context->documentId(),
            (string) $context->node['id'],
            $context->config(),
        );

        return ['requested' => true];
    }

    public function describe(array $config, DocumentType $type): string
    {
        return __('workflow.actions.notify');
    }
}
