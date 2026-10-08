<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\MasterData\CreditLimits\Console\ReconcileCreditLimitChanges;
use App\Core\MasterData\CreditLimits\Listeners\SettleCreditLimitChange;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
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

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCreditLimitChanges::class]);
        }

        // NOT-02: an approved change that could not be applied (an error; the party changed meanwhile).
        foreach ([ApplyCreditLimitChange::FAILED_EVENT => 'apply_failed', CreditLimitChanges::CONFLICT_EVENT => 'conflicted'] as $key => $lang) {
            $this->app->make(EventTypes::class)->register(new EventType(
                key: $key,
                placeholders: ['document_number' => 'CLC-000123', 'party_name' => 'Duka Moja Ltd', 'problem' => 'Apply the request again.'],
                defaultChannels: [Channels::IN_APP, Channels::EMAIL],
                mandatoryAllowed: true,
                langKey: 'core.credit_limit_change.notifications.'.$lang,
            ));
        }
    }
}
