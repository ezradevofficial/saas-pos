<?php

namespace Modules\FuelStation\Sales;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Vouchers\Vouchers;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * FUEL-18, FUEL-21, RBAC-08: the POS events the Fuel station module acts
 * on, only while the tenant has the module (and POS) active, and only for
 * sales with a fuel line or a fuel voucher payment. They carry what POS
 * stored (SaleSnapshot), so nothing here reads POS tables. Queued after the
 * upload commits, in the event's tenant, so a Fuel station failure never
 * fails the till's upload (NFR-04); a failed attempt is retried with
 * backoff, and every step is idempotent per sale.
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
        private readonly FuelSales $sales,
        private readonly Vouchers $vouchers,
    ) {}

    /** No job for a sale without fuel or a voucher, nor for a tenant without both modules (checked in the event's tenant). */
    public function shouldQueue(SaleCompleted|SaleVoided|SaleRefunded $event): bool
    {
        return $this->tenants->id() === $event->tenantId ? $this->relevant($event) : $this->tenants->run($event->tenantId, fn () => $this->relevant($event));
    }

    public function viaQueue(): string
    {
        return 'default';
    }

    public function completed(SaleCompleted $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->sale !== []) {
                $this->vouchers->redeem($event->sale);
                $this->sales->record($event->sale);
            }
        });
    }

    public function voided(SaleVoided $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if ($this->active() && $event->sale !== []) {
                $this->vouchers->reverse($event->sale['id'], $event->voidedAt);
                $this->sales->voided($event->sale, $event->voidedAt);
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

    private function relevant(SaleCompleted|SaleVoided|SaleRefunded $event): bool
    {
        if (! $this->active()) {
            return false;
        }

        if ($event instanceof SaleRefunded) {
            return FuelSale::query()->where('sale_id', $event->saleId)->exists();
        }

        $sale = $event->sale;

        if (collect((array) ($sale['payments'] ?? []))->contains(fn ($p) => ($p['method_type'] ?? null) === 'fuel_voucher')) {
            return true;
        }

        return $event instanceof SaleVoided ? FuelSale::query()->where('sale_id', $event->saleId)->exists() : $this->sales->concerns($sale);
    }

    private function active(): bool
    {
        return $this->modules->isActive(FuelStationServiceProvider::MODULE) && $this->modules->isActive('pos');
    }
}
