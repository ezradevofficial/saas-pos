<?php

namespace App\Core\Rbac;

use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spatie's registrar, made safe around transactions (review focus 4).
 *
 * - A flush inside a transaction is repeated after commit, for the tenant
 *   key current at the time of the change: a concurrent request may have
 *   rebuilt the cache from pre-commit data meanwhile.
 * - Inside a transaction, a cold cache is built from what the transaction
 *   sees but neither stored nor kept in memory: the transaction may roll
 *   back, and its uncommitted roles must never reach the shared cache.
 *
 * The cache key itself is set per tenant by RbacServiceProvider.
 */
class TenantPermissionRegistrar extends PermissionRegistrar
{
    public function forgetCachedPermissions(): bool
    {
        $forgotten = parent::forgetCachedPermissions();

        if ($this->inTransaction()) {
            $store = $this->cache;
            $key = $this->cacheKey;

            DB::connection(TenantContext::CONNECTION)->afterCommit(function () use ($store, $key) {
                $store->forget($key);

                if ($this->cacheKey === $key) {
                    $this->clearPermissionsCollection();
                }
            });
        }

        return $forgotten;
    }

    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        if ($this->permissions !== null || ! $this->inTransaction() || $this->cache->has($this->cacheKey)) {
            return parent::getPermissions($params, $onlyOne);
        }

        $shared = $this->cache;
        $this->cache = new Repository(new ArrayStore);

        try {
            return parent::getPermissions($params, $onlyOne);
        } finally {
            $this->cache = $shared;
            $this->clearPermissionsCollection();
        }
    }

    /**
     * Inside an application transaction on the tenant connection (the
     * wrapping transaction of a database test does not count).
     */
    private function inTransaction(): bool
    {
        return app('db.transactions')->callbackApplicableTransactions()
            ->contains(fn ($transaction) => $transaction->connection === TenantContext::CONNECTION);
    }
}
