<?php

namespace Modules\Pharmacy\Sales;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Pharmacy\PharmacyServiceProvider;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * PHA-16, PHA-26, PHA-27, RBAC-08: the POS events Pharmacy acts on, only
 * while the tenant has both modules active. Queued after the upload
 * commits, in the event's tenant (as Inventory's and Loyalty's listeners
 * are), so a Pharmacy failure never fails the till's upload (NFR-04); a
 * failed attempt is retried with backoff, and the work is idempotent.
 */
class HandlePosEvents implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly Dispensing $dispensing,
    ) {}

    /** No job for a tenant without Pharmacy and POS (checked in the event's tenant). */
    public function shouldQueue(SaleCompleted|SaleVoided|SaleRefunded $event): bool
    {
        return $this->tenants->id() === $event->tenantId ? $this->active() : $this->tenants->run($event->tenantId, fn () => $this->active());
    }

    public function viaQueue(): string
    {
        return 'default';
    }

    public function completed(SaleCompleted $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->sale !== []) {
                $this->dispensing->completed($event->sale);
            }
        });
    }

    public function voided(SaleVoided $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active()) {
                $this->dispensing->voided($event->saleId, $event->voidId);
            }
        });
    }

    public function refunded(SaleRefunded $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active()) {
                $this->dispensing->refunded($event->saleId, $event->refundId);
            }
        });
    }

    private function active(): bool
    {
        return $this->modules->isActive(PharmacyServiceProvider::MODULE) && $this->modules->isActive('pos');
    }
}
