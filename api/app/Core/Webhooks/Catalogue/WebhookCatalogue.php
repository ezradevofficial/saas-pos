<?php

namespace App\Core\Webhooks\Catalogue;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Webhooks\Delivery\WebhookDispatcher;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * HOOK-01: the public event catalogue. Each module registers its own types
 * from its service provider; Laravel boots once for every tenant, so what a
 * tenant is offered and sent follows its modules (RBAC-08): available()
 * and the dispatcher only use types whose module is active.
 *
 * Registering a type with an internal event listens to that event once
 * (WebhookDispatcher), after commit as every catalogued event is.
 */
class WebhookCatalogue
{
    /** The type the "send test event" action sends (never subscribed to). */
    public const TEST = 'webhook.test.v1';

    /** @var array<string, WebhookEventType> */
    private array $types = [];

    /** @var array<class-string, list<string>> internal event => type keys */
    private array $byEvent = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(WebhookEventType $type): void
    {
        if (isset($this->types[$type->key])) {
            throw new InvalidArgumentException("Webhook event type [{$type->key}] is already registered.");
        }

        $this->types[$type->key] = $type;

        if ($type->event === null) {
            return;
        }

        if (! isset($this->byEvent[$type->event])) {
            Event::listen($type->event, [WebhookDispatcher::class, 'handle']);
        }

        $this->byEvent[$type->event][] = $type->key;
    }

    public function find(string $key): ?WebhookEventType
    {
        return $this->types[$key] ?? null;
    }

    /** The type if it exists and its module is active for the current tenant. */
    public function active(string $key): ?WebhookEventType
    {
        $type = $this->find($key);

        return $type !== null && $this->modules->isActive($type->module) ? $type : null;
    }

    /** @return list<WebhookEventType> the types registered for an internal event */
    public function forEvent(string $event): array
    {
        return array_map(fn (string $key) => $this->types[$key], $this->byEvent[$event] ?? []);
    }

    /**
     * The types a tenant may subscribe to: registered, module active, not the test type.
     *
     * @return list<WebhookEventType>
     */
    public function available(): array
    {
        $types = array_filter($this->types, fn (WebhookEventType $type) => $type->key !== self::TEST && $this->modules->isActive($type->module));
        ksort($types);

        return array_values($types);
    }

    /** @return list<WebhookEventType> every registered type (docs, tests) */
    public function all(): array
    {
        $types = $this->types;
        ksort($types);

        return array_values($types);
    }
}
