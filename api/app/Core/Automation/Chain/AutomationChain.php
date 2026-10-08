<?php

namespace App\Core\Automation\Chain;

/**
 * AUTO-06: the chain the code running now belongs to. The rule runner sets
 * it around a rule's actions (until their transaction has committed, so
 * after-commit events see it too); RecordChanged captures it when raised,
 * and the trigger listener reads it for workflow events. Outside a rule's
 * actions there is none: the change starts a new chain.
 */
class AutomationChain
{
    private ?Cause $current = null;

    public function current(): ?Cause
    {
        return $this->current;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function within(Cause $cause, callable $fn): mixed
    {
        $previous = $this->current;
        $this->current = $cause;

        try {
            return $fn();
        } finally {
            $this->current = $previous;
        }
    }
}
