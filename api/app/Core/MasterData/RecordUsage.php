<?php

namespace App\Core\MasterData;

use Closure;

/**
 * IMP-02: where master data records are used, so a batch is not undone
 * from under them. Each module registers, for an entity (`item`,
 * `party`), a reason (`sale`, `price`, `document`) and a check over its
 * own tables that answers which of the given ids it uses (modules never
 * read each other's tables: architecture rule 1). Checks run in the
 * current tenant's context (RLS).
 */
class RecordUsage
{
    /** @var array<string, list<array{reason: string, check: Closure(list<string>): iterable<string>}>> */
    private array $checks = [];

    /** @param Closure(list<string>): iterable<string> $check the ids among those given that are used */
    public function register(string $entity, string $reason, Closure $check): void
    {
        $this->checks[$entity][] = ['reason' => $reason, 'check' => $check];
    }

    /**
     * The given records that are used, with the first reason found.
     *
     * @param  list<string>  $ids
     * @return array<string, string> id => reason (`imports.usage.<reason>`)
     */
    public function used(string $entity, array $ids): array
    {
        $used = [];

        foreach ($this->checks[$entity] ?? [] as $check) {
            $open = array_values(array_diff($ids, array_keys($used)));

            if ($open === []) {
                break;
            }

            foreach (($check['check'])($open) as $id) {
                $used[(string) $id] ??= $check['reason'];
            }
        }

        return $used;
    }
}
