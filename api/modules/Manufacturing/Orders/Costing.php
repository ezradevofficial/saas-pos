<?php

namespace Modules\Manufacturing\Orders;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Events\ValuedEvents;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderOutput;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\ReportLine;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-16: the actual cost of an `inventory`-ledger order's reports, in
 * minor units of the order's currency, under the order's row lock.
 *
 * A report waits (`pending`) until every issue and return of its order is
 * valued by Inventory; then, in the order they were made, each report
 * relieves from work in progress:
 *
 * - materials: the share good ÷ (planned − good costed before) of the
 *   material value not yet relieved (issued − returned − relieved), half
 *   up; all of it when the report reaches or passes the planned quantity;
 *   nothing when it has no good output or nothing is left;
 * - its own labour and overhead: minutes × the order's rate per hour ÷ 60,
 *   half up.
 *
 * Each by-product takes its cost share of that total, rounded down; the
 * good output takes the rest exactly, so the lines add up to what was
 * relieved. Scrap takes nothing. The costed lines go to Inventory as a
 * receipt (MaterialsMoved), whose StockMoved marks the report received; a
 * report with nothing to receive is received at once.
 */
class Costing
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ValuedEvents $valued,
    ) {}

    /** Cost $orderId's pending reports, if all its materials are valued (inside the caller's transaction). */
    public function costPending(string $orderId): void
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

        if ($order === null || ! $order->isValued()) {
            return;
        }

        if (Issue::query()->where('order_id', $order->id)->whereNull('valued_at')->exists()
            || MaterialReturn::query()->where('order_id', $order->id)->whereNull('valued_at')->exists()) {
            return;
        }

        $pending = Report::query()->where('order_id', $order->id)->where('status', Report::PENDING)->orderBy('reported_at')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            return;
        }

        $goodBefore = BigDecimal::of((string) (Report::query()->where('order_id', $order->id)->whereIn('status', [Report::COSTED, Report::RECEIVED])->sum('qty_good_base') ?: '0'));
        $shares = OrderOutput::query()->where('order_id', $order->id)->pluck('cost_share_percent', 'id');

        foreach ($pending as $report) {
            $good = BigDecimal::of((string) $report->qty_good_base);
            $remaining = BigDecimal::of((string) $order->qty_base)->minus($goodBefore);
            $open = BigInteger::of((string) $order->material_cost_minor)->minus((string) $order->material_relieved_minor);

            $material = match (true) {
                ! $open->isPositive(), $good->isZero() => BigInteger::zero(),
                ! $remaining->isPositive(), $good->isGreaterThanOrEqualTo($remaining) => $open,
                default => Qty::share($open, $good, $remaining),
            };

            $labour = Qty::perMinutes($report->labour_minutes, (string) $order->labour_rate_minor);
            $overhead = Qty::perMinutes($report->labour_minutes, (string) $order->overhead_rate_minor);
            $total = $material->plus($labour)->plus($overhead);
            $lines = ReportLine::query()->where('report_id', $report->id)->whereIn('kind', [ReportLine::PRODUCT, ReportLine::BY_PRODUCT])->orderBy('line_no')->get();
            $relieved = BigInteger::zero();
            $product = null;

            foreach ($lines as $line) {
                if ($line->kind === ReportLine::PRODUCT) {
                    $product = $line;

                    continue;
                }

                $cost = Qty::percentDown($total, (string) ($shares[$line->output_id] ?? '0'));
                $line->update(['cost_minor' => (string) $cost]);
                $relieved = $relieved->plus($cost);
            }

            if ($product !== null) {
                $product->update(['cost_minor' => (string) $total->minus($relieved)]);
                $relieved = $total;
            }

            $report->fill([
                'status' => Report::COSTED, 'costed_at' => CarbonImmutable::now(), 'currency' => $order->currency,
                'material_cost_minor' => (string) $material, 'labour_cost_minor' => (string) $labour, 'overhead_cost_minor' => (string) $overhead,
                'cost_minor' => (string) $relieved,
            ])->save();

            $order->fill([
                'material_relieved_minor' => (string) BigInteger::of((string) $order->material_relieved_minor)->plus($material),
                'labour_cost_minor' => (string) BigInteger::of((string) $order->labour_cost_minor)->plus($labour),
                'overhead_cost_minor' => (string) BigInteger::of((string) $order->overhead_cost_minor)->plus($overhead),
                'relieved_minor' => (string) BigInteger::of((string) $order->relieved_minor)->plus($relieved),
            ])->save();
            $goodBefore = $goodBefore->plus($good);

            $this->receive($order, $report->refresh());
        }
    }

    /** The costed product and by-products into stock (Inventory), or received at once when there is nothing to receive. */
    private function receive(Order $order, Report $report): void
    {
        $lines = ReportLine::query()->where('report_id', $report->id)->whereIn('kind', [ReportLine::PRODUCT, ReportLine::BY_PRODUCT])->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            $report->fill(['status' => Report::RECEIVED, 'received_at' => CarbonImmutable::now()])->save();
            event($this->valued->production($report));

            return;
        }

        $uoms = Item::query()->whereKey($lines->pluck('item_id'))->pluck('base_uom_id', 'id');

        MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::RECEIPT, $report->id, $report->number, $order->company_id, $order->branch_id,
            $order->location_id, $report->reported_at->toIso8601String(), $report->reported_by,
            $lines->map(fn (ReportLine $line) => [
                'line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => (string) $uoms[$line->item_id],
                'qty' => Qty::show($line->qty_base), 'qty_base' => Qty::show($line->qty_base), 'cost_minor' => (string) $line->cost_minor,
            ])->values()->all());
    }
}
