<?php

namespace App\Core\Automation\Actions;

use Illuminate\Contracts\Container\Container;

/**
 * The automation actions (AUTO-03) by key. The core registers update
 * field, change stage, assign user, send notification, create document,
 * set credit hold and call webhook; modules may register more in their
 * provider's boot().
 */
class AutomationActions
{
    /** @var array<string, AutomationAction> */
    private array $actions = [];

    public function __construct(private readonly Container $container) {}

    /** @param AutomationAction|class-string<AutomationAction> $action */
    public function register(AutomationAction|string $action): void
    {
        $instance = is_string($action) ? $this->container->make($action) : $action;
        $this->actions[$instance->key()] = $instance;
    }

    public function find(string $key): ?AutomationAction
    {
        return $this->actions[$key] ?? null;
    }

    /** @return array<string, AutomationAction> by key, sorted */
    public function all(): array
    {
        $actions = $this->actions;
        ksort($actions);

        return $actions;
    }
}
