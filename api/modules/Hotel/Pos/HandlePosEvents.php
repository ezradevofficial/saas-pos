<?php

namespace Modules\Hotel\Pos;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Hotel\HotelServiceProvider;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * HOT-22, RBAC-08: the POS events Hotel acts on, only while the tenant has
 * both modules active, and only for sales with a room_charge payment. They
 * carry what POS stored (SaleSnapshot), so nothing here reads POS tables.
 * Registered by HotelServiceProvider only when the POS module's events exist.
 *
 * Queued after the upload commits, in the event's tenant (as Loyalty's are),
 * so a Hotel failure never fails the till's upload (NFR-04); a failed attempt
 * is retried with backoff, and posting is idempotent per payment.
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
        private readonly PosCharges $charges,
    ) {}

    /** No job for a sale without a room charge, nor for a tenant without both modules (checked in the event's tenant). */
    public function shouldQueue(SaleCompleted|SaleVoided|SaleRefunded $event): bool
    {
        $snapshot = $event instanceof SaleRefunded ? $event->refund : $event->sale;

        if (! $this->hasRoomCharge($snapshot)) {
            return false;
        }

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
                $this->charges->completed($event->sale);
            }
        });
    }

    public function voided(SaleVoided $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->sale !== []) {
                $this->charges->voided($event->sale);
            }
        });
    }

    public function refunded(SaleRefunded $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->refund !== []) {
                $this->charges->refunded($event->refund);
            }
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function hasRoomCharge(array $snapshot): bool
    {
        foreach ((array) ($snapshot['payments'] ?? []) as $payment) {
            if (($payment['method_type'] ?? null) === 'room_charge') {
                return true;
            }
        }

        return false;
    }

    private function active(): bool
    {
        return $this->modules->isActive(HotelServiceProvider::MODULE) && $this->modules->isActive('pos');
    }
}
