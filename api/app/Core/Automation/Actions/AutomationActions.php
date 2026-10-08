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
    /** @var array<string, AutomationAction|class-string<AutomationAction>> */
    private array $actions = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register under the action's key. A class is resolved again on use, so
     * its dependencies are the container's current ones.
     *
     * @param  AutomationAction|class-string<AutomationAction>  $action
     */
    public function register(AutomationAction|string $action): void
    {
        $key = is_string($action) ? $this->container->make($action)->key() : $action->key();
        $this->actions[$key] = $action;
    }

    public function find(string $key): ?AutomationAction
    {
        $action = $this->actions[$key] ?? null;

        return is_string($action) ? $this->container->make($action) : $action;
    }

    /** @return array<string, AutomationAction> by key, sorted */
    public function all(): array
    {
        $actions = [];

        foreach (array_keys($this->actions) as $key) {
            $actions[$key] = $this->find($key);
        }

        ksort($actions);

        return $actions;
    }
}
