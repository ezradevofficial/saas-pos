<?php

namespace App\Core\Support;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Stage 7B (Accounting backfill): modules that raise domain events say how
 * to raise them again for their records of a period, so a module switched
 * on later (Accounting) can catch up on what happened before, without
 * reading another module's tables (architecture rule 1).
 *
 * A replayer runs in the current tenant and hands each event object to
 * `$emit` in the order the records happened; it never dispatches them
 * (other listeners have handled them already). Listeners that catch up
 * must be idempotent: a replayed event is the same event again.
 */
class EventReplays
{
    /** @var array<string, Closure(CarbonImmutable, CarbonImmutable, Closure(object): void): void> */
    private array $replayers = [];

    /** @param Closure(CarbonImmutable $from, CarbonImmutable $to, Closure(object): void $emit): void $replayer */
    public function register(string $module, Closure $replayer): void
    {
        $this->replayers[$module] = $replayer;
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_keys($this->replayers);
    }

    /**
     * Replay the events of $modules for [$from, $to] in the current tenant;
     * returns how many were emitted.
     *
     * @param  list<string>  $modules
     * @param  Closure(object): void  $emit
     */
    public function replay(array $modules, CarbonImmutable $from, CarbonImmutable $to, Closure $emit): int
    {
        $count = 0;

        foreach ($modules as $module) {
            if (isset($this->replayers[$module])) {
                ($this->replayers[$module])($from, $to, function (object $event) use ($emit, &$count): void {
                    $count++;
                    $emit($event);
                });
            }
        }

        return $count;
    }
}
