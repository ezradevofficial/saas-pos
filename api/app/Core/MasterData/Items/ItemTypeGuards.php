<?php

namespace App\Core\MasterData\Items;

use App\Core\Http\ApiException;
use Closure;

/**
 * MD-02: modules that rely on an item's type refuse a change that would
 * break them (e.g. Fixed assets keeps an item flagged as an asset from
 * becoming a stock item, FA-21). Each module registers a check over its
 * own tables (architecture rule 1); ItemWriter runs them, under its
 * transaction, when an update changes the type. A check returns null to
 * allow, or throws (an ApiException naming `type`).
 */
class ItemTypeGuards
{
    /** @var list<Closure(Item, string): void> */
    private array $guards = [];

    /** @param Closure(Item $item, string $newType): void $guard throws to refuse */
    public function register(Closure $guard): void
    {
        $this->guards[] = $guard;
    }

    /** @throws ApiException */
    public function assert(Item $item, string $newType): void
    {
        foreach ($this->guards as $guard) {
            $guard($item, $newType);
        }
    }
}
