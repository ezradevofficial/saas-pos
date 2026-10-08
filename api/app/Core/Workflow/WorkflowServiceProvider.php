<?php

namespace App\Core\Workflow;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Calendar\Console\LoadPublicHolidays;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\Console\ProcessStageTimersCommand;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowNotificationRequested;
use App\Core\Workflow\Handlers\ActionHandlers;
use App\Core\Workflow\Handlers\ApprovalHandler;
use App\Core\Workflow\Handlers\CreateDocumentAction;
use App\Core\Workflow\Handlers\ManualApprovalHandler;
use App\Core\Workflow\Handlers\NotifyAction;
use App\Core\Workflow\Listeners\SendWorkflowNotification;
use App\Core\Workflow\Runtime\StageTimers;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The process and workflow engine (WF-01..WF-11, APR-09): the document
 * type registry modules register into, the action handlers, the approval
 * handler (manual until the approvals service rebinds ApprovalHandler),
 * the condition evaluator and the business calendar.
 *
 * A module registers its document types in its own provider's boot():
 *
 *   $this->app->make(DocumentTypeRegistry::class)->register(LeaveRequestType::class);
 *
 * and more action handlers the same way through ActionHandlers.
 */
class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentTypeRegistry::class);
        $this->app->singleton(ActionHandlers::class);
        $this->app->singleton(ConditionEvaluator::class);
        // Holds only global holiday data (cached per country and year).
        $this->app->singleton(BusinessCalendar::class);
        $this->app->bindIf(ApprovalHandler::class, ManualApprovalHandler::class, shared: true);
    }

    public function boot(): void
    {
        $actions = $this->app->make(ActionHandlers::class);
        $actions->register(CreateDocumentAction::class);
        $actions->register(NotifyAction::class);

        // NOT-02: `notify` nodes send through the Notifier (texts in lang/*/workflow.php).
        $this->app->make(EventTypes::class)->register(new EventType(
            key: SendWorkflowNotification::EVENT,
            placeholders: ['document_type' => 'Purchase requisition', 'step' => 'Create draft purchase order', 'message' => 'Please prepare the order.'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'workflow.notifications.notify',
        ));
        Event::listen(WorkflowNotificationRequested::class, SendWorkflowNotification::class);

        // WF-09: plain stages' reminders and overdue notices (StageTimers).
        foreach ([StageTimers::REMINDER => 'stage_reminder', StageTimers::OVERDUE => 'stage_overdue'] as $key => $lang) {
            $this->app->make(EventTypes::class)->register(new EventType(
                key: $key,
                placeholders: ['document_type' => 'Purchase requisition', 'document_number' => 'PR-0042', 'step' => 'Check budget', 'due' => '2026-10-08 17:00'],
                defaultChannels: [Channels::IN_APP, Channels::EMAIL],
                mandatoryAllowed: true,
                langKey: 'workflow.notifications.'.$lang,
            ));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([LoadPublicHolidays::class, ProcessStageTimersCommand::class]);
        }
    }
}
