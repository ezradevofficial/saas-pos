<?php

namespace App\Core\Notifications\Mail;

use App\Core\Notifications\Models\NotificationDelivery;

/**
 * Buttons an email of some event types carries besides its link, made
 * when the email is sent (never stored with the message), e.g. the
 * single-use approve and reject links of an approval request (APR-08).
 * A module registers a provider per event type in its provider's boot():
 *
 *   app(MailActions::class)->register('core.approval.requested', fn (NotificationDelivery $d) => [...]);
 *
 * Providers run in the delivery's tenant and return
 * `[['label' => 'Approve', 'url' => 'https://…'], …]` in the delivery's language.
 */
class MailActions
{
    /** @var array<string, callable(NotificationDelivery): list<array{label: string, url: string}>> */
    private array $providers = [];

    /** @param callable(NotificationDelivery): list<array{label: string, url: string}> $provider */
    public function register(string $eventType, callable $provider): void
    {
        $this->providers[$eventType] = $provider;
    }

    /** @return list<array{label: string, url: string}> */
    public function for(NotificationDelivery $delivery): array
    {
        $provider = $this->providers[$delivery->event_type] ?? null;

        return $provider === null ? [] : array_values($provider($delivery));
    }
}
