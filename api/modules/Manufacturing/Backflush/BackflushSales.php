<?php

namespace Modules\Manufacturing\Backflush;

use App\Core\Tenancy\TenantContext;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * MFG-07, MFG-08, NFR-04: POS sales, voids and refunds queue their
 * backflush (BackflushSale, ids only) after the upload commits, and only
 * while Manufacturing and Inventory are active for the tenant (memoised
 * module flags, read in the upload's own tenant: no query per sale). The
 * job checks again and does the work; nothing here can fail the till's
 * upload.
 */
class BackflushSales
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Backflushes $backflushes,
    ) {}

    public function handle(SaleCompleted|SaleVoided|SaleRefunded $event): void
    {
        $active = fn () => $this->backflushes->active();

        if (! ($this->tenants->id() === $event->tenantId ? $active() : $this->tenants->run($event->tenantId, $active))) {
            return;
        }

        match (true) {
            $event instanceof SaleCompleted => BackflushSale::dispatch($event->tenantId, Backflushes::SALE, $event->saleId),
            $event instanceof SaleVoided => BackflushSale::dispatch($event->tenantId, Backflushes::VOID, $event->voidId),
            default => BackflushSale::dispatch($event->tenantId, Backflushes::REFUND, $event->refundId),
        };
    }
}
