<?php

namespace App\Core\CustomFields\Entities;

use App\Core\Identity\Models\User;

/**
 * CF-01: what a lookup field may point at (items, parties, users, or any
 * registered entity). Every answer is limited to the records the actor
 * may see (TEN-01 by row-level security, RBAC-04 by the target's policy),
 * so a lookup never stores, confirms or names a record outside them.
 */
interface LookupTarget
{
    /** `item`, `party`, `user`, ...: [a-z][a-z0-9_]* */
    public function key(): string;

    /** Translation key of the target's name. */
    public function label(): string;

    /**
     * Display labels of those $ids the actor may see, by id.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function labels(User $actor, array $ids): array;

    /**
     * Records the actor may see whose label matches $search, best first.
     *
     * @return list<array{id: string, label: string}>
     */
    public function search(User $actor, string $search, int $limit): array;
}
