<?php

namespace App\Core\Webhooks;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use App\Core\Webhooks\Console\SweepWebhooksCommand;
use App\Core\Webhooks\Delivery\WebhookDeliverer;
use App\Core\Webhooks\Principals\WebhookPrincipal;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowStageEntered;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * HOOK-01: webhooks (docs/adr/021-webhooks.md). The catalogue modules
 * register their public events into, the core events (the test event and
 * document stage changes), the "subscription switched off" notification,
 * and the sweep command. Memos (subscribed types, principals) start afresh
 * for every request and queued job.
 */
class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebhookCatalogue::class);
        $this->app->singleton(WebhookSubscriptions::class);
        $this->app->singleton(WebhookPrincipals::class);
    }

    public function boot(): void
    {
        $catalogue = $this->app->make(WebhookCatalogue::class);

        // The "send test event" action's type: sent to one subscription on request, never subscribed to.
        $catalogue->register(new WebhookEventType(
            key: WebhookCatalogue::TEST,
            module: ModuleRegistry::CORE,
            event: null,
            permission: null,
            fields: ['message'],
            authorise: fn () => true,
        ));

        // WF-10: a document entered a stage or approval of its flow. Found only for
        // document types of active modules (DocumentTypeRegistry), and only for
        // principals who may see that document.
        $catalogue->register(new WebhookEventType(
            key: 'document.stage_changed.v1',
            module: ModuleRegistry::CORE,
            event: WorkflowStageEntered::class,
            permission: null,
            capture: fn (WorkflowStageEntered $e) => new WebhookOccurrence([
                'document_type' => $e->documentType,
                'document_id' => $e->documentId,
                'workflow_id' => $e->workflowId,
                'stage' => ['id' => $e->nodeId, 'type' => $e->nodeType],
            ], document: ['type' => $e->documentType, 'id' => $e->documentId]),
            fields: ['document_type', 'document_id', 'workflow_id', 'stage.id', 'stage.type', 'company_id', 'branch_id', 'location_id'],
            resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                $type = $this->app->make(DocumentTypeRegistry::class)->find((string) $occurrence->document['type']);
                $scope = $type?->scope((string) $occurrence->document['id']);

                if ($type === null || $scope === null) {
                    return null;
                }

                return $occurrence->at($scope->companyId, $scope->branchId, $scope->locationId)->with([
                    ...$occurrence->data,
                    'company_id' => $scope->companyId,
                    'branch_id' => $scope->branchId,
                    'location_id' => $scope->locationId,
                ]);
            },
            authorise: function (WebhookPrincipal $principal, WebhookOccurrence $occurrence): bool {
                $type = $occurrence->document === null ? null : $this->app->make(DocumentTypeRegistry::class)->find((string) $occurrence->document['type']);

                if ($type === null || ! $principal->can($type->viewPermission(), $occurrence->scope())) {
                    return false;
                }

                $user = $principal->actingUser();

                return $user === null || $type->sees($user, (string) $occurrence->document['id']);
            },
        ));

        $this->app->make(EventTypes::class)->register(new EventType(
            key: WebhookDeliverer::DISABLED_EVENT,
            placeholders: ['url' => 'https://example.com/hooks', 'failures' => '10'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            channels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'webhooks.notifications.disabled',
        ));

        $flush = function () {
            $this->app->make(WebhookSubscriptions::class)->flush();
            $this->app->make(WebhookPrincipals::class)->flush();
        };
        Event::listen(JobProcessing::class, $flush);
        $this->app->rebinding('request', $flush);

        if ($this->app->runningInConsole()) {
            $this->commands([SweepWebhooksCommand::class]);
        }
    }
}
