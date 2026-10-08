<?php

namespace App\Core\Workflow\Handlers;

use Illuminate\Contracts\Container\Container;

/** The action handlers `action` nodes may name (WF-07; AUTO-03 adds more). */
class ActionHandlers
{
    /** @var array<string, ActionHandler> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** @param ActionHandler|class-string<ActionHandler> $handler */
    public function register(ActionHandler|string $handler): void
    {
        $instance = is_string($handler) ? $this->container->make($handler) : $handler;
        $this->handlers[$instance->key()] = $instance;
    }

    public function find(string $key): ?ActionHandler
    {
        return $this->handlers[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->handlers);
        sort($keys);

        return $keys;
    }
}
