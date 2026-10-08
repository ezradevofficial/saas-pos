<?php

namespace App\Core\Automation;

use App\Core\Automation\Actions\AssignUserAction;
use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\ChangeStageAction;
use App\Core\Automation\Actions\CreateDocumentAction;
use App\Core\Automation\Actions\NotifyAction;
use App\Core\Automation\Actions\SetCreditHoldAction;
use App\Core\Automation\Actions\UpdateFieldAction;
use App\Core\Automation\Actions\WebhookAction;
use App\Core\Automation\Chain\AutomationChain;
use App\Core\Automation\Console\ScanAutomationTriggers;
use App\Core\Automation\Events\RecordChanged;
use App\Core\Automation\Runtime\FailureAlert;
use App\Core\Automation\Runtime\TriggerListener;
use App\Core\Automation\Templates\AlertBelowLevel;
use App\Core\Automation\Templates\RemindBeforeDate;
use App\Core\Automation\Templates\RuleTemplates;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Automation\Webhooks\DnsHostResolver;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Workflow\Events\WorkflowStageEntered;
use App\Core\Workflow\Events\WorkflowStageLeft;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * AUTO-01..AUTO-07: automation rules. Modules raise RecordChanged for
 * their document types (RaisesRecordChanges), may register more actions
 * (AutomationActions) and ready-made rules (RuleTemplates) in their own
 * provider's boot(), and offer capabilities by implementing the
 * interfaces in Capabilities (writable and assignable fields, credit
 * hold, date search, links).
 */
class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AutomationActions::class);
        $this->app->singleton(RuleTemplates::class);
        $this->app->singleton(Triggers::class);
        $this->app->scoped(AutomationChain::class);
        $this->app->bindIf(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        $actions = $this->app->make(AutomationActions::class);

        foreach ([UpdateFieldAction::class, ChangeStageAction::class, AssignUserAction::class, NotifyAction::class,
            CreateDocumentAction::class, SetCreditHoldAction::class, WebhookAction::class] as $action) {
            $actions->register($action);
        }

        $templates = $this->app->make(RuleTemplates::class);
        $templates->register(RemindBeforeDate::class);
        $templates->register(AlertBelowLevel::class);

        // NOT-02: what rules send, and the alert when a run fails for good (texts in lang/*/automation.php).
        $events = $this->app->make(EventTypes::class);
        $events->register(new EventType(
            key: NotifyAction::EVENT,
            placeholders: ['subject' => 'Contract ends in 30 days', 'message' => 'The contract with Juma Traders ends on 7 Nov 2026.', 'rule_name' => 'Contract expiry reminder', 'document_type' => 'Contract'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'automation.notifications.notify',
        ));
        $events->register(new EventType(
            key: FailureAlert::EVENT,
            placeholders: ['rule_name' => 'Contract expiry reminder', 'error' => 'The webhook answered 503.', 'attempts' => '3'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'automation.notifications.failed',
        ));

        Event::listen(RecordChanged::class, [TriggerListener::class, 'recordChanged']);
        Event::listen(WorkflowStageEntered::class, [TriggerListener::class, 'stageEntered']);
        Event::listen(WorkflowStageLeft::class, [TriggerListener::class, 'stageLeft']);

        if ($this->app->runningInConsole()) {
            $this->commands([ScanAutomationTriggers::class]);
        }
    }
}
