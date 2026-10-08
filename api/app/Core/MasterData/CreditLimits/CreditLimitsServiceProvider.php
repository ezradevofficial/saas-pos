<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\MasterData\CreditLimits\Listeners\SettleCreditLimitChange;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * MD-01, WF-01: the first core document type, a party's credit limit
 * change (`core.credit_limit_change`), decided through a flow and applied
 * to the party on approval. Registered after the workflow and approvals
 * providers.
 */
class CreditLimitsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(DocumentTypeRegistry::class)->register(CreditLimitChangeType::class);

        Event::listen(WorkflowCompleted::class, SettleCreditLimitChange::class);
        Event::listen(WorkflowCancelled::class, SettleCreditLimitChange::class);
    }
}
