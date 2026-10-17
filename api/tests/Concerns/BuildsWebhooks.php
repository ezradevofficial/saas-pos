<?php

namespace Tests\Concerns;

use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Models\WebhookSubscription;
use Illuminate\Support\Collection;
use Modules\POS\PosServiceProvider;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Webhooks\ThingHappened;

/**
 * HOOK-01 tests: an organisation (BuildsOrganisation) with the POS module
 * active, a test event type `thing.happened.v1` of that module (received
 * with `pos.sale.view`; `note` hidden by the field rule `pos_thing.note`),
 * DNS answers without the network, and helpers to subscribe and read
 * deliveries.
 */
trait BuildsWebhooks
{
    use BuildsOrganisation;

    protected FakeHostResolver $dns;

    protected function setUpWebhooks(): void
    {
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate(PosServiceProvider::MODULE));

        app(WebhookCatalogue::class)->register(new WebhookEventType(
            key: 'thing.happened.v1',
            module: PosServiceProvider::MODULE,
            event: ThingHappened::class,
            permission: 'pos.sale.view',
            capture: fn (ThingHappened $e) => new WebhookOccurrence([
                'thing_id' => $e->thingId,
                'company_id' => $e->companyId,
                'branch_id' => $e->branchId,
                'location_id' => $e->locationId,
                'note' => $e->note,
                'amount_minor' => $e->amountMinor,
            ], $e->companyId, $e->branchId, $e->locationId),
            fields: ['thing_id', 'company_id', 'branch_id', 'location_id', 'note', 'amount_minor'],
            hiddenBy: ['pos_thing' => ['note' => ['note']]],
        ));

        $this->dns = new FakeHostResolver([
            'hooks.example.com' => [['93.184.216.34']],
            'other.example.com' => [['93.184.216.35']],
            'intranet.example.com' => [['10.0.0.7']],
            'metadata.example.com' => [['169.254.169.254']],
        ]);
        $this->app->instance(HostResolver::class, $this->dns);
    }

    /**
     * Subscribe through the API as $user (the owner by default).
     *
     * @param  array<string, mixed>  $values
     * @return array{id: string, secret: string}
     */
    protected function subscribe(array $values = [], ?User $user = null): array
    {
        $response = $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.com/in?token=abc',
            'events' => ['thing.happened.v1'],
            ...$values,
        ], $this->headersFor($user))->assertCreated();

        return ['id' => $response->json('data.id'), 'secret' => $response->json('data.secret')];
    }

    protected function happen(array $values = []): void
    {
        $this->inTenant(fn () => ThingHappened::dispatch(...[
            'tenantId' => $this->owner->tenant_id,
            'thingId' => 'thing-1',
            'companyId' => $this->acme->id,
            'branchId' => $this->branchA->id,
            'locationId' => $this->locationA->id,
            ...$values,
        ]));
    }

    /** @return Collection<int, WebhookDelivery> */
    protected function deliveries(): Collection
    {
        return $this->inTenant(fn () => WebhookDelivery::query()->orderBy('created_at')->orderBy('id')->get());
    }

    protected function subscription(string $id): WebhookSubscription
    {
        return $this->inTenant(fn () => WebhookSubscription::query()->findOrFail($id));
    }
}
