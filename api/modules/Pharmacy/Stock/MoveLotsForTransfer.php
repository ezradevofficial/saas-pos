<?php

namespace Modules\Pharmacy\Stock;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Contracts\TransferLines;
use Modules\Inventory\Events\TransferReceived;
use Modules\Pharmacy\PharmacyServiceProvider;

/**
 * PHA-13: Inventory's own transfers also move lots. When a transfer is
 * received, the lots of tracked items leave the source first-expiry-first-out
 * and arrive at the destination (Transfers::moveForInventoryTransfer), once
 * per transfer line. Only while Pharmacy is active for the tenant; what the
 * transfer moved is read through Inventory's TransferLines contract.
 */
class MoveLotsForTransfer implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly Transfers $transfers,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(TransferReceived $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(PharmacyServiceProvider::MODULE)) {
                return;
            }

            $transfer = TransferLines::forTransfer($event->transferId);

            if ($transfer !== null) {
                $this->transfers->moveForInventoryTransfer($transfer);
            }
        });
    }
}
