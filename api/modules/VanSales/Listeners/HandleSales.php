<?php

namespace Modules\VanSales\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\VanSales\Runs\SaleLinks;
use Modules\VanSales\VanSalesServiceProvider;

/**
 * VAN-10, VAN-16, RBAC-08: a POS sale that carries `extensions.van_sales`
 * joins its run; its void and a refund at a van take their share back. The
 * events carry what POS stored (snapshots), so no POS table is read. Queued
 * after the upload commits and run in the event's tenant, so a failure here
 * never fails the till's upload (NFR-04); a retry finds the rows made.
 */
class HandleSales implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly SaleLinks $links,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(SaleCompleted|SaleVoided|SaleRefunded $event): bool
    {
        // Every sale is looked at: whether it belongs to a van is decided by where it was made, not by what the device says (VAN-10).
        return $this->tenants->id() === $event->tenantId ? $this->active() : $this->tenants->run($event->tenantId, fn () => $this->active());
    }

    public function completed(SaleCompleted $event): void
    {
        $this->in($event->tenantId, fn () => $this->links->completed($event->saleId, $event->locationId, $event->sale));
    }

    public function voided(SaleVoided $event): void
    {
        $this->in($event->tenantId, fn () => $this->links->voided($event->saleId, $event->sale, $event->voidedAt));
    }

    public function refunded(SaleRefunded $event): void
    {
        $this->in($event->tenantId, fn () => $this->links->refunded($event->refundId, $event->refund));
    }

    private function in(string $tenantId, callable $fn): void
    {
        $this->tenants->run($tenantId, function () use ($fn) {
            if (! $this->active()) {
                return;
            }

            DB::connection(TenantContext::CONNECTION)->transaction($fn);
        });
    }

    private function active(): bool
    {
        return $this->modules->isActive(VanSalesServiceProvider::MODULE);
    }
}
