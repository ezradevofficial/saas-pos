<?php

namespace Modules\Restaurant\Http\Controllers;

use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Billing\Bill;
use Modules\Restaurant\Http\Requests\ViewRequest;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Orders\OrderActions;
use Modules\Restaurant\Support\Places;

/**
 * REST-11: the voids of sent lines that a till asked for and the server could not prove, waiting for a reviewer.
 * Listed for holders of `pos.sale.void` at the lines' locations; `can_apply` says whether the reader's own limit
 * covers the line's value (the void route refuses otherwise). Applying uses the void route, rejecting `.../void/reject`.
 */
class PendingVoidController
{
    public function __construct(private readonly Places $places, private readonly OrderActions $actions) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = OrderLine::query()->join('rest_orders', 'rest_orders.id', '=', 'rest_order_lines.order_id')
            ->whereNotNull('rest_order_lines.pending_void')->whereNull('rest_order_lines.voided_at')->where('rest_orders.status', 'open')
            ->orderBy('rest_order_lines.updated_at')->orderBy('rest_order_lines.id')->select('rest_order_lines.*');
        $this->places->filterLocations($query, $request->user(), $request->permission(), 'rest_order_lines.location_id');

        if ($request->filled('location_id')) {
            $query->where('rest_order_lines.location_id', $request->input('location_id'));
        }

        $lines = $query->limit(200)->get();
        $orders = Order::query()->whereKey($lines->pluck('order_id')->unique()->all())->get()->keyBy('id');
        $tables = Table::query()->whereKey($orders->pluck('table_id')->filter()->unique()->all())->pluck('name', 'id');
        $people = $lines->flatMap(fn (OrderLine $l) => [$l->pending_void['by'] ?? null, $l->pending_void['approver_id'] ?? null])->filter()->unique()->values()->all();
        $names = User::query()->whereKey($people)->pluck('name', 'id');

        return response()->json(['data' => $lines->map(function (OrderLine $line) use ($orders, $tables, $names, $request) {
            $order = $orders[$line->order_id];
            $pending = $line->pending_void;
            $value = (string) Bill::line([
                'qty' => (string) $line->qty, 'unit_price_minor' => (string) $line->unit_price_minor, 'modifiers' => $line->modifiers ?? [],
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate, 'tax_inclusive' => (bool) $line->tax_inclusive,
            ])['payable'];

            return [
                'order_id' => $order->id, 'location_id' => $order->location_id, 'order_kind' => $order->kind,
                'table_name' => $tables[$order->table_id] ?? null, 'tab_name' => $order->tab_name,
                'line_id' => $line->id, 'name' => $line->name, 'qty' => rtrim(rtrim((string) $line->qty, '0'), '.'), 'value_minor' => $value, 'currency' => $order->currency,
                'asked_by_id' => $pending['by'] ?? null, 'asked_by_name' => $names[$pending['by'] ?? ''] ?? null,
                'named_approver_id' => $pending['approver_id'] ?? null, 'named_approver_name' => $names[$pending['approver_id'] ?? ''] ?? null,
                'reason' => $pending['reason'] ?? null, 'asked_at' => $pending['at'] ?? null, 'flags' => $pending['flags'] ?? [],
                'can_apply' => $this->actions->voidRefusal($request->user(), $order, $value) === null,
            ];
        })->values()->all()]);
    }
}
