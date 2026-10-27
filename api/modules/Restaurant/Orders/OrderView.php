<?php

namespace Modules\Restaurant\Orders;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Restaurant\Billing\Bill;
use Modules\Restaurant\Billing\Charges;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Models\Ticket;

/**
 * The order as the API and the sync source show it (docs/modules/restaurant.md,
 * Response shapes): one place, so the back office, the till's pull and the
 * upload answer agree. Loads what it needs in a fixed number of queries per
 * call of `many`.
 */
class OrderView
{
    public function __construct(private readonly Charges $charges) {}

    /**
     * @param  iterable<Order>  $orders
     * @return list<array<string, mixed>>
     */
    public function many(iterable $orders, bool $detail = true): array
    {
        $orders = new EloquentCollection(Collection::make($orders)->values()->all());

        if ($orders->isEmpty()) {
            return [];
        }

        $orders->load(['lines', 'tickets.lines', 'settlements']);
        $names = User::query()->whereKey($orders->pluck('waiter_id')->filter()->unique()->all())->pluck('name', 'id');
        $tables = Table::query()->whereKey($orders->pluck('table_id')->filter()->unique()->all())->pluck('name', 'id');
        $stations = Station::query()->whereKey($orders->flatMap(fn (Order $o) => $o->tickets->pluck('station_id'))->filter()->unique()->all())->pluck('name', 'id');

        return $orders->map(fn (Order $order) => $this->one($order, $detail, $names[$order->waiter_id] ?? null, $tables[$order->table_id] ?? null, $stations))->all();
    }

    /** @return array<string, mixed> */
    public function record(Order $order, bool $detail = true): array
    {
        return $this->many([$order], $detail)[0];
    }

    /**
     * The bill of an order's live lines (REST-13).
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return array<string, mixed>
     */
    public function bill(Order $order, Collection $lines): array
    {
        $bill = Bill::totals($this->billLines($lines), $this->charges->service($order->company_id), (string) $order->tip_minor);

        return [
            'lines_minor' => $bill['lines_minor'],
            'service_charge_minor' => $bill['service_charge_minor'],
            'service_tax_minor' => $bill['service_tax_minor'],
            'tip_minor' => $bill['tip_minor'],
            'total_minor' => $bill['total_minor'],
        ];
    }

    /**
     * @param  Collection<int, OrderLine>  $lines
     * @return list<array<string, mixed>>
     */
    public function billLines(Collection $lines): array
    {
        return $lines->map(fn (OrderLine $line) => [
            'id' => $line->id,
            'qty' => (string) $line->qty,
            'unit_price_minor' => (string) $line->unit_price_minor,
            'modifiers' => $line->modifiers,
            'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
            'tax_inclusive' => (bool) $line->tax_inclusive,
            'seat' => $line->seat,
            'void' => $line->voided_at !== null,
        ])->values()->all();
    }

    /** @param Collection<string, string> $stations */
    private function one(Order $order, bool $detail, ?string $waiter, ?string $table, Collection $stations): array
    {
        $statuses = [];

        foreach ($order->tickets as $ticket) {
            foreach ($ticket->lines as $ticketLine) {
                if ($ticket->kind !== 'void') {
                    $statuses[$ticketLine->order_line_id] = $ticket->status;
                }
            }
        }

        $row = [
            'id' => $order->id,
            'location_id' => $order->location_id,
            'kind' => $order->kind,
            'table_id' => $order->table_id,
            'table_name' => $table,
            'tab_name' => $order->tab_name,
            'customer_id' => $order->customer_id,
            'waiter_id' => $order->waiter_id,
            'waiter_name' => $waiter,
            'covers' => (int) $order->covers,
            'status' => $order->status,
            'currency' => $order->currency,
            'note' => $order->note,
            'merged_into_id' => $order->merged_into_id,
            'opened_at' => $order->opened_at->toIso8601String(),
            'updated_at' => $order->header_at->toIso8601String(),
            'updated_by' => $order->updated_by,
            'closed_at' => $order->closed_at?->toIso8601String(),
            'bill_requested_at' => $order->bill_requested_at?->toIso8601String(),
            'flags' => $order->flags ?? [],
            'rev' => (int) $order->rev,
            'bill' => $this->bill($order, $order->lines),
        ];

        if (! $detail) {
            return $row;
        }

        return $row + [
            'lines' => $order->lines->map(fn (OrderLine $line) => $this->line($line, $statuses[$line->id] ?? null))->all(),
            'tickets' => $order->tickets->map(fn (Ticket $ticket) => $this->ticketSummary($ticket, $stations[$ticket->station_id] ?? null, $order, $waiter, $table))->all(),
            'settlements' => $order->settlements->map(fn ($s) => [
                'id' => $s->id, 'sale_id' => $s->sale_id, 'mode' => $s->mode, 'part' => (int) $s->part, 'parts' => (int) $s->parts,
                'final' => (bool) $s->final, 'currency' => $s->currency, 'total_minor' => (string) $s->total_minor,
                'settled_at' => $s->settled_at->toIso8601String(), 'voided_at' => $s->voided_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function line(OrderLine $line, ?string $ticketStatus): array
    {
        return [
            'id' => $line->id,
            'item_id' => $line->item_id,
            'name' => $line->name,
            'qty' => $this->qty((string) $line->qty),
            'unit_price_minor' => (string) $line->unit_price_minor,
            'tax_code_id' => $line->tax_code_id,
            'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
            'tax_inclusive' => (bool) $line->tax_inclusive,
            'course' => (int) $line->course,
            'seat' => $line->seat === null ? null : (int) $line->seat,
            'note' => $line->note,
            'modifiers' => $line->modifiers ?? [],
            'round' => $line->round,
            'created_at' => $line->created_at_device->toIso8601String(),
            'sent_at' => $line->sent_at?->toIso8601String(),
            'sent_by' => $line->sent_by,
            'ticket_status' => $ticketStatus,
            'flags' => $line->flags ?? [],
            'void_pending' => $line->pending_void === null ? null : ['at' => $line->pending_void['at'] ?? null, 'by' => $line->pending_void['by'] ?? null, 'reason' => $line->pending_void['reason'] ?? null, 'approver_id' => $line->pending_void['approver_id'] ?? null],
            'void' => $line->voided_at === null ? null : [
                'at' => $line->voided_at->toIso8601String(),
                'by' => $line->voided_by,
                'reason' => $line->void_reason,
                'after_send' => (bool) $line->void_after_send,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function ticketSummary(Ticket $ticket, ?string $station, Order $order, ?string $waiter, ?string $table, bool $mask = false): array
    {
        return [
            'id' => $ticket->id,
            'number' => (int) $ticket->number,
            'order_id' => $order->id,
            'location_id' => $ticket->location_id,
            'station_id' => $ticket->station_id,
            'station_name' => $station,
            'kind' => $ticket->kind,
            'status' => $ticket->status,
            'course' => (int) $ticket->course,
            'round' => $ticket->round,
            'table_name' => $table,
            'tab_name' => $mask ? self::initials($order->tab_name) : $order->tab_name,
            'waiter_name' => $waiter,
            'covers' => (int) $order->covers,
            'created_at' => $ticket->created_at->toIso8601String(),
            'preparing_at' => $ticket->preparing_at?->toIso8601String(),
            'ready_at' => $ticket->ready_at?->toIso8601String(),
            'served_at' => $ticket->served_at?->toIso8601String(),
            'lines' => $ticket->lines->map(fn ($l) => [
                'id' => $l->id, 'order_line_id' => $l->order_line_id, 'name' => $l->name, 'qty' => $this->qty((string) $l->qty),
                'modifiers' => array_map(fn ($m) => ['name' => $m['name'] ?? ''], $l->modifiers ?? []), 'note' => $l->note, 'void' => (bool) $l->void,
            ])->all(),
        ];
    }

    /** 2.000000 shows as 2, 0.500000 as 0.5. */
    private function qty(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }

    /** @return array<string, mixed> a ticket on its own (the display feed) */
    public function ticket(Ticket $ticket): array
    {
        $ticket->loadMissing(['lines', 'order', 'station']);
        $order = $ticket->order;
        $waiter = $order->waiter_id === null ? null : User::query()->whereKey($order->waiter_id)->value('name');
        $table = $order->table_id === null ? null : Table::query()->whereKey($order->table_id)->value('name');

        // The kitchen display shows a tab by its initials: the name of a guest is not the kitchen's to read.
        return $this->ticketSummary($ticket, $ticket->station?->name, $order, $waiter, $table, mask: true);
    }

    private static function initials(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return collect(preg_split('/\\s+/u', trim($name)))->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)).'.')->implode('');
    }
}
