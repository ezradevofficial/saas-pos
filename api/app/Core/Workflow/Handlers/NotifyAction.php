<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Events\WorkflowNotificationRequested;

/**
 * A `notify` action node (spec 6.4 palette "Notify"). Config:
 * `to` (a list of `role:<template key or role id>` and `user:<user id>`)
 * and an optional `message` (at most 500 characters). Running it records
 * the request and dispatches WorkflowNotificationRequested after commit;
 * SendWorkflowNotification resolves the recipients within the document's
 * scope and sends `core.workflow.notify` through the Notifier.
 */
class NotifyAction implements ActionHandler
{
    public const KEY = 'notify';

    public function __construct(private readonly NotifyRecipients $recipients) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(array $config, DocumentType $type): array
    {
        $to = $config['to'] ?? null;

        if ((! is_string($to) && ! (is_array($to) && array_is_list($to))) || NotifyRecipients::entries($config) === []
            || (is_array($to) && count(NotifyRecipients::entries($config)) !== count($to))) {
            return [__('workflow.validation.notify_to')];
        }

        if (isset($config['message']) && (! is_string($config['message']) || mb_strlen($config['message']) > 500)) {
            return [__('workflow.validation.notify_message')];
        }

        $unknown = $this->recipients->unknown($config);

        return $unknown === [] ? [] : [__('workflow.validation.notify_unknown', ['recipients' => implode(', ', $unknown)])];
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

        return ['requested' => true, 'to' => NotifyRecipients::entries($context->config())];
    }

    public function describe(array $config, DocumentType $type): string
    {
        return __('workflow.actions.notify');
    }
}
