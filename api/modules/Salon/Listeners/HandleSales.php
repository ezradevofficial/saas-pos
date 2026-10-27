<?php

namespace Modules\Salon\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\Salon\Sales\SaleLinks;
use Modules\Salon\SalonServiceProvider;

/**
 * SAL-16 to SAL-18, SAL-21: a POS sale, its void and a refund reach the salon (appointment, credit, packages,
 * commissions). The events carry what POS stored (snapshots), so no POS table is read. Queued after the upload commits
 * and run in the event's tenant, so a failure here never fails the till's upload (NFR-04); a retry finds the rows made.
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
        return $this->tenants->id() === $event->tenantId ? $this->active() : $this->tenants->run($event->tenantId, fn () => $this->active());
    }

    public function completed(SaleCompleted $event): void
    {
        $this->in($event->tenantId, fn () => $this->links->completed($event->saleId, $event->companyId, $event->sale));
    }

    public function voided(SaleVoided $event): void
    {
        $this->in($event->tenantId, fn () => $this->links->voided($event->saleId, (string) ($event->sale['company_id'] ?? ''), $event->sale, $event->voidId, $event->voidedAt));
    }

    public function refunded(SaleRefunded $event): void
    {
        $this->in($event->tenantId, fn () => $this->links->refunded((string) ($event->refund['company_id'] ?? ''), $event->refund));
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
        return $this->modules->isActive(SalonServiceProvider::MODULE);
    }
}
