<?php

namespace App\Core\Search;

/**
 * LAY-01, RBAC-08: one record type the quick search can find. Core owns this
 * contract and never reads a module's tables; each module registers its
 * searchers with RecordSearch in its service provider.
 *
 * A searcher is cheap: an indexed column and a LIMIT. It runs only when its
 * module is active for the tenant and the user holds `permission` somewhere
 * (RecordSearch checks both); it narrows the rows to the places the user
 * holds it with `$context->visible`, and never matches or shows a field the
 * user's field rules hide (RBAC-05).
 */
interface RecordSearcher
{
    /** The result group id, e.g. `customer` (the web labels it through translations). */
    public function type(): string;

    /** The module that owns the records (`core` is always active). */
    public function module(): string;

    /** The permission that lets a user find these records (`module.resource.view`). */
    public function permission(): string;

    /** @return list<RecordHit> at most `$term->limit` hits */
    public function search(SearchTerm $term, SearchContext $context): array;
}
