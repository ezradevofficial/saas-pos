<?php

namespace App\Core\Workflow;

use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Calendar\Console\LoadPublicHolidays;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Handlers\ActionHandlers;
use App\Core\Workflow\Handlers\ApprovalHandler;
use App\Core\Workflow\Handlers\CreateDocumentAction;
use App\Core\Workflow\Handlers\ManualApprovalHandler;
use App\Core\Workflow\Handlers\NotifyAction;
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

        if ($this->app->runningInConsole()) {
            $this->commands([LoadPublicHolidays::class]);
        }
    }
}
