<?php

namespace App\Core\Automation\Chain;

/**
 * AUTO-06 across queues: a job a rule's action dispatches (directly or
 * through a module) takes the automation chain with it, so the changes it
 * makes later, on a worker, still count towards the same chain (loop and
 * depth checks). A job uses the trait, calls captureAutomationCause() when
 * it is created, and lists `new RestoresAutomationCause` in middleware():
 *
 *     public function __construct(...) { $this->captureAutomationCause(); }
 *     public function middleware(): array { return [new TenantAware, new RestoresAutomationCause]; }
 */
trait CarriesAutomationCause
{
    /** @var array{chain_id: string, depth: int, rules: list<string>}|null */
    public ?array $automationCause = null;

    protected function captureAutomationCause(): void
    {
        $this->automationCause = app(AutomationChain::class)->current()?->toArray();
    }
}
