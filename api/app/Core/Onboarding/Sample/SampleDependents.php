<?php

namespace App\Core\Onboarding\Sample;

use Closure;

/**
 * ONB-06: who depends on sample records. Core and each module register a
 * check over the batch (`{item: [ids], party: [ids], ...}`) that returns
 * the real records still using a sample one, read from their own tables
 * (a module never reads another's). Removing the batch is refused while
 * any check returns something.
 */
class SampleDependents
{
    /** @var list<Closure(array<string, list<string>>): list<array{type: string, id: string, used_by: string}>> */
    private array $checks = [];

    /** @param Closure(array<string, list<string>>): list<array{type: string, id: string, used_by: string}> $check */
    public function register(Closure $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * @param  array<string, list<string>>  $records
     * @return list<array{type: string, id: string, used_by: string}>
     */
    public function find(array $records): array
    {
        $found = [];

        foreach ($this->checks as $check) {
            array_push($found, ...$check($records));
        }

        return $found;
    }
}
