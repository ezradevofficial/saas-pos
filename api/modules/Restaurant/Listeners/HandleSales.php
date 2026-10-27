<?php

namespace Modules\Restaurant\Listeners;

use App\Core\Audit\Auditor;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleVoided;
use Modules\Restaurant\Billing\Charges;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Orders\OrderActions;
use Modules\Restaurant\Orders\OrderView;
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
        private readonly OrderView $view,
        private readonly Auditor $auditor,
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
        $this->tenants->run($event->tenantId, fn () => $this->atomically(function () use ($event) {
            $ext = $event->sale['extensions']['restaurant'] ?? null;

            if (! $this->active() || ! is_array($ext) || empty($ext['order_id'])) {
                return;
            }

            $order = Order::query()->whereKey($ext['order_id'])->lockForUpdate()->first();

            // A sale already settled (a retry) or voided before it arrived (a void that overtook it) settles nothing.
            if ($order === null || $order->location_id !== $event->locationId || Settlement::query()->where('sale_id', $event->saleId)->exists()) {
                return;
            }

            $soldAt = CarbonImmutable::parse($event->sale['sold_at'] ?? now())->utc();
            $charges = $this->charges->for($order->company_id);
            $sum = fn (?string $itemId) => $itemId === null ? 0 : array_reduce($event->sale['lines'] ?? [], fn (int $c, array $l) => $c + ($l['item_id'] === $itemId ? (int) $l['total_minor'] : 0), 0);
            // Only lines of this order count as covered: the till's list is checked, not trusted.
            $named = array_values(array_filter((array) ($ext['line_ids'] ?? []), fn ($id) => is_string($id) && Str::isUuid($id)));
            $own = $named === [] ? [] : OrderLine::query()->where('order_id', $order->id)->whereIn('id', $named)->pluck('id')->all();
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
                'line_ids' => $own,
                'settled_at' => $soldAt,
            ]);

            // REST-16: the till says it is the last part, the server decides whether the bill is paid.
            if (! empty($ext['final']) || $this->flagged($order, 'underpaid')) {
                $this->close($order, $soldAt);
            }
        }));
    }

    public function voided(SaleVoided $event): void
    {
        $this->tenants->run($event->tenantId, fn () => $this->atomically(function () use ($event) {
            if (! $this->active()) {
                return;
            }

            $at = CarbonImmutable::parse($event->voidedAt ?? now())->utc();
            $settlement = Settlement::query()->where('sale_id', $event->saleId)->lockForUpdate()->first();

            if ($settlement === null) {
                // The void overtook the sale's completion: remember the sale as voided so its late completion settles nothing.
                $ext = $event->sale['extensions']['restaurant'] ?? null;
                $order = is_array($ext) && ! empty($ext['order_id']) ? Order::query()->whereKey($ext['order_id'])->first() : null;

                if ($order !== null && $order->location_id === ($event->sale['location_id'] ?? null)) {
                    Settlement::create([
                        'order_id' => $order->id, 'location_id' => $order->location_id, 'sale_id' => $event->saleId, 'mode' => 'full', 'currency' => $event->sale['currency'],
                        'total_minor' => $event->sale['totals']['total_minor'] ?? 0, 'line_ids' => [], 'settled_at' => $at, 'voided_at' => $at,
                    ]);
                }

                return;
            }

            if ($settlement->voided_at !== null) {
                return;
            }

            $settlement->voided_at = $at;
            $settlement->save();
            $order = Order::query()->whereKey($settlement->order_id)->lockForUpdate()->first();

            if ($order !== null && ! Settlement::query()->where('order_id', $order->id)->whereNull('voided_at')->exists()) {
                $this->actions->reopen($order);
            }
        }));
    }

    /**
     * Closes $order when every live line is covered by a live settlement of the lines (not of an equal split, which
     * names them all) or the live settlements pay the bill (less rounding of a split); else it stays open, flagged
     * `underpaid`, for a later sale or a manager.
     */
    private function close(Order $order, CarbonImmutable $at): void
    {
        $lines = OrderLine::query()->where('order_id', $order->id)->get();
        $live = $lines->whereNull('voided_at');
        $settlements = Settlement::query()->where('order_id', $order->id)->whereNull('voided_at')->get();
        $covered = $settlements->where('mode', '!=', 'equal')->flatMap(fn (Settlement $s) => $s->line_ids ?? [])->unique()->all();
        $allCovered = $live->isNotEmpty() && $live->pluck('id')->diff($covered)->isEmpty();
        $bill = BigInteger::of($this->view->bill($order, $lines)['total_minor']);
        $paid = $settlements->reduce(fn (BigInteger $sum, Settlement $s) => $sum->plus((string) $s->total_minor), BigInteger::zero());
        // Equal parts take 1/n of every line, so each part may differ from its share by a unit of rounding per line.
        $paysBill = $paid->plus(max(2, $live->count() * 2))->isGreaterThanOrEqualTo($bill);

        if ($allCovered || $paysBill) {
            $order->flags = array_values(array_filter($order->flags ?? [], fn ($f) => ($f['code'] ?? $f) !== 'underpaid'));
            $order->save();
            $this->actions->close($order, $at);

            return;
        }

        if (! $this->flagged($order, 'underpaid')) {
            $order->flags = $this->actions->withFlag($order->flags, 'underpaid');
            $order->rev++;
            $order->save();
            $this->auditor->record('restaurant.order.underpaid', $order, null, ['bill_minor' => (string) $bill, 'paid_minor' => (string) $paid]);
        }
    }

    private function flagged(Order $order, string $code): bool
    {
        return collect($order->flags ?? [])->contains(fn ($f) => ($f['code'] ?? $f) === $code);
    }

    private function atomically(callable $fn): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }

    private function active(): bool
    {
        return $this->modules->isActive(RestaurantServiceProvider::MODULE);
    }
}
