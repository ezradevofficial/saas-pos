<?php

namespace App\Core\Notifications;

use App\Core\Rbac\ModuleRegistry;
use InvalidArgumentException;

/**
 * The registry of notification event types (NOT-02). Each module
 * registers its own at boot; the core registers `core.notification.test`.
 */
class EventTypes
{
    /** Placeholders every event has: filled by the Notifier, never by the sender. */
    public const COMMON = ['recipient_name', 'app_name'];

    /** @var array<string, EventType> */
    private array $types = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(EventType $type): void
    {
        foreach (self::COMMON as $name) {
            if (array_key_exists($name, $type->placeholders)) {
                throw new InvalidArgumentException("[{$type->key}] may not declare the common placeholder [{$name}].");
            }
        }

        $this->types[$type->key] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): EventType
    {
        return $this->types[$key] ?? throw new UnknownEventType($key);
    }

    /** @return array<string, EventType> every registered type, by key */
    public function all(): array
    {
        ksort($this->types);

        return $this->types;
    }

    /**
     * The types of the core and of the modules the current tenant has
     * active (RBAC-08): what users and admins configure.
     *
     * @return array<string, EventType>
     */
    public function active(): array
    {
        $active = $this->modules->active();

        return array_filter($this->all(), fn (EventType $type) => in_array($type->module, $active, true));
    }

    public function isActive(string $key): bool
    {
        return isset($this->active()[$key]);
    }
}
