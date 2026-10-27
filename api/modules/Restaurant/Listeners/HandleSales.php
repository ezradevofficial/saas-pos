<?php

namespace Modules\Restaurant\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleVoided;
use Modules\Restaurant\Billing\Charges;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Orders\OrderActions;
use Modules\Restaurant\RestaurantServiceProvider;

/**
 * REST-16, RBAC-08: a POS sale that carries `extensions.restaurant`
 * settles (part of) an order; a voided one takes the settlement back. The
 * events carry what POS stored (SaleSnapshot), so no POS table is read.
 * Queued after the upload commits, in the event's tenant, so a failure here
 * never fails the till's upload (NFR-04); a retry finds the settlement made
 * and does nothing (one per sale).
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
        private readonly OrderActions $actions,
        private readonly Charges $charges,
    ) {}

    public function shouldQueue(SaleCompleted|SaleVoided $event): bool
    {
        if (! isset($event->sale['extensions']['restaurant'])) {
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
            $ext = $event->sale['extensions']['restaurant'] ?? null;

            if (! $this->active() || ! is_array($ext) || empty($ext['order_id'])) {
                return;
            }

            $order = Order::query()->whereKey($ext['order_id'])->lockForUpdate()->first();

            if ($order === null || $order->location_id !== $event->locationId || Settlement::query()->where('sale_id', $event->saleId)->exists()) {
                return;
            }

            $soldAt = CarbonImmutable::parse($event->sale['sold_at'] ?? now())->utc();
            $charges = $this->charges->for($order->company_id);
            $sum = fn (?string $itemId) => $itemId === null ? 0 : array_reduce($event->sale['lines'] ?? [], fn (int $c, array $l) => $c + ($l['item_id'] === $itemId ? (int) $l['total_minor'] : 0), 0);
            Settlement::create([
                'order_id' => $order->id,
                'location_id' => $order->location_id,
                'sale_id' => $event->saleId,
                'mode' => in_array($ext['mode'] ?? 'full', ['full', 'item', 'seat', 'equal'], true) ? $ext['mode'] : 'full',
                'part' => max(1, (int) ($ext['part'] ?? 1)),
                'parts' => max(1, (int) ($ext['parts'] ?? 1)),
                'final' => (bool) ($ext['final'] ?? false),
                'currency' => $event->sale['currency'],
                'total_minor' => $event->sale['totals']['total_minor'] ?? 0,
                'tip_minor' => $sum($charges['tip_item_id']),
                'service_charge_minor' => $sum($charges['service_item_id']),
                'line_ids' => array_values(array_filter((array) ($ext['line_ids'] ?? []), 'is_string')),
                'settled_at' => $soldAt,
            ]);

            if (! empty($ext['final'])) {
                $this->actions->close($order, $soldAt);
            }
        });
    }

    public function voided(SaleVoided $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->active()) {
                return;
            }

            $settlement = Settlement::query()->where('sale_id', $event->saleId)->whereNull('voided_at')->lockForUpdate()->first();

            if ($settlement === null) {
                return;
            }

            $settlement->voided_at = CarbonImmutable::parse($event->voidedAt ?? now())->utc();
            $settlement->save();
            $order = Order::query()->whereKey($settlement->order_id)->lockForUpdate()->first();

            if ($order !== null && ! Settlement::query()->where('order_id', $order->id)->whereNull('voided_at')->exists()) {
                $this->actions->reopen($order);
            }
        });
    }

    private function active(): bool
    {
        return $this->modules->isActive(RestaurantServiceProvider::MODULE);
    }
}
