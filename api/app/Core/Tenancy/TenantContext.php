<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Holds the current tenant and mirrors it into the PostgreSQL session
 * setting `app.tenant_id`, which every row-level security policy reads (TEN-01).
 *
 * set_config is transactional, so a rollback can revert the database value.
 * CoreServiceProvider re-applies the PHP value after every rollback and on
 * every (re)connect, so PHP stays the source of truth.
 */
class TenantContext
{
    public const CONNECTION = 'pgsql';

    private ?string $tenantId = null;

    /** @var list<callable(?string): void> */
    private array $listeners = [];

    public function set(?string $tenantId): void
    {
        // Database first: if it fails, PHP keeps the value the database still has.
        $this->applyTo(DB::connection(self::CONNECTION), $tenantId);

        $this->tenantId = $tenantId;
        $this->notify($tenantId);
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function require(): string
    {
        return $this->tenantId ?? throw new TenantContextMissing;
    }

    /**
     * Run $fn inside $tenantId's context, then restore the previous context,
     * even when $fn throws. The original exception is never masked.
     */
    public function run(string $tenantId, callable $fn): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenantId);

        try {
            $result = $fn();
        } catch (Throwable $e) {
            $this->restore($previous, $e);

            throw $e;
        }

        $this->restore($previous);

        return $result;
    }

    /**
     * Register a listener called with the new tenant id (or null) on every change.
     * Used to key caches such as the permission cache by tenant (RBAC).
     */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Write the current PHP tenant into $connection's session setting.
     */
    public function syncTo(Connection $connection): void
    {
        $this->applyTo($connection, $this->tenantId);
    }

    private function applyTo(Connection $connection, ?string $tenantId): void
    {
        // Session-level (false): requests are not wrapped in a transaction.
        $connection->select("select set_config('app.tenant_id', ?, false)", [$tenantId ?? '']);
    }

    private function restore(?string $previous, ?Throwable $original = null): void
    {
        try {
            $this->set($previous);
        } catch (Throwable $restoreFailed) {
            // PHP always goes back to the previous tenant.
            $this->tenantId = $previous;
            $this->notify($previous);

            $connection = DB::connection(self::CONNECTION);

            // Inside an (aborted) transaction: the rollback listener re-syncs.
            if ($connection->transactionLevel() > 0) {
                return;
            }

            // Outside a transaction the database value is unknown: drop the
            // connection so the reconnect listener re-applies the PHP value.
            $connection->disconnect();

            if ($original === null) {
                throw $restoreFailed;
            }

            report($restoreFailed);
        }
    }

    private function notify(?string $tenantId): void
    {
        foreach ($this->listeners as $listener) {
            $listener($tenantId);
        }
    }
}
