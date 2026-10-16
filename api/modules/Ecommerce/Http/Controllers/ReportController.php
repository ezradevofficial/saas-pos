<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;

/**
 * ECOM-36: a store's (or every visible store's) period: orders, revenue
 * and average order per currency (cancelled and returned orders left
 * out), by channel, payment kind and status, top products, deliveries
 * delivered and failed, and cash on delivery not cashed up per rider.
 * Within `ecommerce.report.view` at the orders' locations (RBAC-04).
 * Periods are calendar days in the company's time zone.
 */
class ReportController
{
    public function __construct(private readonly Access $access) {}

    public function summary(ListRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'ecommerce.report.view'), 403);
        $zone = "(select coalesce(companies.timezone, 'UTC') from companies where companies.id = ecom_orders.company_id)";
        $orders = fn (): Builder => $this->access->byLocation(Order::query(), $user, 'ecommerce.report.view')
            ->when($request->filled('store'), fn ($q) => $q->where('store_id', $request->validated('store')))
            ->when($request->filled('from'), fn ($q) => $q->whereRaw("ecom_orders.placed_at >= (?::date)::timestamp at time zone {$zone}", [$request->validated('from')]))
            ->when($request->filled('to'), fn ($q) => $q->whereRaw("ecom_orders.placed_at < ((?::date) + 1)::timestamp at time zone {$zone}", [$request->validated('to')]));
        $sold = fn (): Builder => $orders()->whereNotIn('status', [Order::CANCELLED, Order::RETURNED]);
        $group = fn (string $column) => $orders()->groupBy($column)->orderBy($column)->selectRaw("{$column} as key, count(*) as orders")->get()
            ->map(fn ($row) => ['key' => $row->key, 'orders' => (int) $row->orders])->values();

        $revenue = $sold()->groupBy('currency')->orderBy('currency')->selectRaw('currency, count(*) as orders, sum(total_minor) as total, sum(delivery_fee_minor) as fees')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'orders' => (int) $row->orders, 'total_minor' => (string) $row->total,
                'average_minor' => (string) intdiv((int) $row->total, max(1, (int) $row->orders)), 'delivery_fees_minor' => (string) $row->fees])->values();

        $top = OrderLine::query()->whereIn('order_id', $sold()->select('id'))
            ->groupBy('item_id', 'name')->orderByRaw('sum(qty) desc')->limit(10)
            ->selectRaw('item_id, name, sum(qty) as qty, count(distinct order_id) as orders')->get()
            ->map(fn ($row) => ['item_id' => $row->item_id, 'name' => $row->name, 'qty' => (string) $row->qty, 'orders' => (int) $row->orders])->values();

        $deliveries = Delivery::query()->whereIn('order_id', $orders()->select('id'))->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        $outstanding = Delivery::query()->whereIn('order_id', $orders()->select('id'))->where('status', Delivery::DELIVERED)->whereNotNull('cod_collected_minor')
            ->where('cod_due_minor', '>', 0)->whereNull('cashup_id')->groupBy('rider_id', 'currency')
            ->selectRaw('rider_id, currency, count(*) as deliveries, sum(cod_due_minor) as due')->get();
        $names = Rider::query()->whereKey($outstanding->pluck('rider_id'))->pluck('name', 'id');

        return response()->json(['data' => [
            'revenue' => $revenue,
            'by_channel' => $group('channel'),
            'by_payment' => $group('payment_kind'),
            'by_status' => $group('status'),
            'top_products' => $top,
            'deliveries' => ['delivered' => $deliveries[Delivery::DELIVERED] ?? 0, 'failed' => $deliveries[Delivery::FAILED] ?? 0,
                'open' => ($deliveries[Delivery::UNASSIGNED] ?? 0) + ($deliveries[Delivery::ASSIGNED] ?? 0) + ($deliveries[Delivery::PICKED_UP] ?? 0)],
            'cod_outstanding' => $outstanding->map(fn ($row) => ['rider' => ['id' => $row->rider_id, 'name' => $names[$row->rider_id] ?? null], 'currency' => $row->currency,
                'deliveries' => (int) $row->deliveries, 'due_minor' => (string) $row->due])->values(),
        ]]);
    }
}
