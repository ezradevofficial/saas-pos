<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Holds the current tenant and mirrors it into the PostgreSQL session
 * setting `app.tenant_id`, which every row-level security policy reads (TEN-01).
 */
class TenantContext
{
    public const CONNECTION = 'pgsql';

    private ?string $tenantId = null;

    /** @var list<callable(?string): void> */
    private array $listeners = [];

    public function set(?string $tenantId): void
    {
        // Session-level (false): requests are not wrapped in a transaction.
        DB::connection(self::CONNECTION)
            ->select("select set_config('app.tenant_id', ?, false)", [$tenantId ?? '']);

        $this->tenantId = $tenantId;

        foreach ($this->listeners as $listener) {
            $listener($tenantId);
        }
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
     * even when $fn throws.
     */
    public function run(string $tenantId, callable $fn): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenantId);

        try {
            return $fn();
        } finally {
            $this->set($previous);
        }
    }

    /**
     * Register a listener called with the new tenant id (or null) on every change.
     * Used to key caches such as the permission cache by tenant (RBAC).
     */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }
}
