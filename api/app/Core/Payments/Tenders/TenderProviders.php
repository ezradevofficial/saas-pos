<?php

namespace App\Core\Payments\Tenders;

use App\Core\Rbac\ModuleRegistry;
use InvalidArgumentException;

/**
 * MD-04: the tender providers modules register, one per payment method
 * type (the Loyalty module: `points`, `voucher`, `credit`). A provider of
 * a module the tenant has not activated is not found (RBAC-08), so the
 * tender is stored as sent and nothing is checked; `registered()` still
 * names it, so the upload can flag a tender of a switched-off module.
 */
class TenderProviders
{
    /** @var array<string, TenderProvider> */
    private array $providers = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(TenderProvider $provider): void
    {
        $type = $provider->type();

        if (isset($this->providers[$type]) && $provider::class !== $this->providers[$type]::class) {
            throw new InvalidArgumentException("A tender provider for [{$type}] is already registered.");
        }

        $this->providers[$type] = $provider;
    }

    /** The provider of $type registered by any module, active or not, or null. */
    public function registered(string $type): ?TenderProvider
    {
        return $this->providers[$type] ?? null;
    }

    /** The active provider of $type in the current tenant, or null. */
    public function for(string $type): ?TenderProvider
    {
        $provider = $this->providers[$type] ?? null;

        return $provider !== null && $this->modules->isActive($provider->module()) ? $provider : null;
    }
}
