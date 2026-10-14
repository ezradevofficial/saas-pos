<?php

namespace Modules\Loyalty\Pos;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * LOY-26, RBAC-08: the POS events Loyalty acts on, only while the tenant
 * has both modules active. The events carry what POS stored (SaleSnapshot),
 * so nothing here reads POS tables. Registered by LoyaltyServiceProvider
 * only when the POS module's events exist.
 *
 * Queued after the upload commits, in the event's tenant (as Inventory's
 * listeners are), so a Loyalty failure never fails the till's upload or
 * stops another module's handling of the same event (NFR-04); a failed
 * attempt is retried with backoff, and a sale left `failed` is picked up
 * by `loyalty:reprocess`.
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
        private readonly PosSales $sales,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function completed(SaleCompleted $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->sale !== []) {
                $this->sales->completed($event->sale);
            }
        });
    }

    public function voided(SaleVoided $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active()) {
                $this->sales->voided($event->saleId, $event->voidId);
            }
        });
    }

    public function refunded(SaleRefunded $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->refund !== []) {
                $this->sales->refunded($event->refund);
            }
        });
    }

    private function active(): bool
    {
        return $this->modules->isActive(LoyaltyServiceProvider::MODULE) && $this->modules->isActive('pos');
    }
}
