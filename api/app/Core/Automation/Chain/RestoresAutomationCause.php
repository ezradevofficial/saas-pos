<?php

namespace App\Core\Automation\Chain;

use Closure;

/**
 * Job middleware: runs a job that carries an automation chain
 * (CarriesAutomationCause) inside that chain, so RecordChanged events and
 * workflow moves it causes are attributed to it (AUTO-06).
 */
class RestoresAutomationCause
{
    public function handle(object $job, Closure $next): mixed
    {
        $cause = Cause::fromArray($job->automationCause ?? null);

        return $cause === null ? $next($job) : app(AutomationChain::class)->within($cause, fn () => $next($job));
    }
}
