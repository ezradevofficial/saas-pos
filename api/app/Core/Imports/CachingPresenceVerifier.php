<?php

namespace App\Core\Imports;

use Closure;
use Illuminate\Validation\DatabasePresenceVerifierInterface;
use Illuminate\Validation\PresenceVerifierInterface;

/**
 * IMP-03: during an import, the presence checks the domain rules run on
 * reference data (units, categories, tax categories, companies, price
 * lists, currencies) are answered once per batch instead of once per row.
 * Only these tables are cached, and only checks without an excluded id:
 * that covers `exists` rules, and also `unique` rules on these tables that
 * ignore no id. No importer writes any of these tables, so an answer can't
 * go stale within a batch; the cache is cleared at every batch, and tables
 * an import writes (items, parties, prices) always reach the database.
 * The rules themselves are unchanged (RLS still applies).
 */
class CachingPresenceVerifier implements DatabasePresenceVerifierInterface
{
    public const TABLES = ['uoms', 'item_categories', 'tax_categories', 'companies', 'price_lists', 'tenant_currencies'];

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly PresenceVerifierInterface $inner) {}

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        $key = $this->key($collection, $excludeId, [$column, $value, $extra]);

        if ($key === null) {
            return $this->inner->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        }

        return $this->counts[$key] ??= $this->inner->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        $key = $this->key($collection, null, ['multi', $column, $values, $extra]);

        if ($key === null) {
            return $this->inner->getMultiCount($collection, $column, $values, $extra);
        }

        return $this->counts[$key] ??= $this->inner->getMultiCount($collection, $column, $values, $extra);
    }

    public function setConnection($connection)
    {
        if ($this->inner instanceof DatabasePresenceVerifierInterface) {
            $this->inner->setConnection($connection);
        }
    }

    public function inner(): PresenceVerifierInterface
    {
        return $this->inner;
    }

    /** Forget every answer (a new batch). */
    public function forget(): void
    {
        $this->counts = [];
    }

    private function key(string $collection, mixed $excludeId, array $parts): ?string
    {
        if ($excludeId !== null || ! in_array($collection, self::TABLES, true)) {
            return null;
        }

        $closure = false;
        array_walk_recursive($parts, function (mixed $part) use (&$closure) {
            $closure = $closure || $part instanceof Closure;
        });

        return $closure ? null : $collection.'|'.serialize($parts);
    }
}
