<?php

namespace Modules\Restaurant\Orders;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\CompanyClock;
use Illuminate\Support\Collection;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\StationRoute;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Models\TicketLine;
use Modules\Restaurant\Support\Errors;

/**
 * REST-08, REST-09, REST-10: routing lines to stations and the tickets
 * they make. A line goes to the station its item category is routed to at
 * the location, else the location's default station, else a ticket with no
 * station (the display's "Unrouted" view). One ticket per station, round
 * and course; a re-send and a void make tickets of their own.
 */
class Tickets
{
    public const STATUSES = ['new', 'preparing', 'ready', 'served'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * New tickets for $lines of $order. $kind is `order`, `resend` or `void`.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return list<Ticket>
     */
    public function make(Order $order, Collection $lines, string $kind = 'order', bool $offline = false, ?string $by = null): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $stations = $this->stationsFor($order->location_id, $lines);
        $groups = $lines->groupBy(fn (OrderLine $line) => ($stations[$line->item_id] ?? '-').'|'.$line->round.'|'.$line->course);
        $tickets = [];
        $date = CompanyClock::today($order->company_id, $order->branch_id);

        foreach ($groups as $group) {
            /** @var Collection<int, OrderLine> $group */
            $first = $group->first();
            Errors::lock("restaurant.ticket.{$order->location_id}.{$date}");
            $number = (int) Ticket::query()->where('location_id', $order->location_id)->where('business_date', $date)->max('number') + 1;
            $ticket = Ticket::create([
                'order_id' => $order->id,
                'location_id' => $order->location_id,
                'station_id' => $stations[$first->item_id] ?? null,
                'business_date' => $date,
                'number' => $number,
                'kind' => $kind,
                'status' => 'new',
                'course' => $first->course,
                'round' => $first->round,
                'offline' => $offline,
                'updated_by' => $by,
            ]);

            foreach ($group as $line) {
                TicketLine::create([
                    'ticket_id' => $ticket->id,
                    'order_line_id' => $line->id,
                    'name' => $line->name,
                    'qty' => (string) $line->qty,
                    'modifiers' => $line->modifiers,
                    'note' => $line->note,
                    'void' => $kind === 'void',
                ]);
            }

            $tickets[] = $ticket;
        }

        return $tickets;
    }

    /**
     * REST-09: a ticket moves new, preparing, ready, served (or back, to recall it).
     */
    public function setStatus(Ticket $ticket, string $status, ?User $by): Ticket
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw Errors::make(422, 'ticket_status_invalid', field: 'status');
        }

        if ($ticket->status === $status) {
            return $ticket;
        }

        $before = $ticket->status;
        $now = now();
        $ticket->status = $status;
        $ticket->preparing_at = in_array($status, ['preparing', 'ready', 'served'], true) ? ($ticket->preparing_at ?? $now) : null;
        $ticket->ready_at = in_array($status, ['ready', 'served'], true) ? ($ticket->ready_at ?? $now) : null;
        $ticket->served_at = $status === 'served' ? $now : null;
        $ticket->updated_by = $by?->id;
        $ticket->save();

        $this->auditor->record('restaurant.ticket.status', $ticket, ['status' => $before], ['status' => $status], array_filter(['user_id' => $by?->id]));

        return $ticket;
    }

    /**
     * Station id by item id for $lines at $locationId.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return array<string, ?string>
     */
    private function stationsFor(string $locationId, Collection $lines): array
    {
        $categories = Item::query()->whereKey($lines->pluck('item_id')->unique()->all())->pluck('category_id', 'id');
        $routes = StationRoute::query()->where('location_id', $locationId)
            ->whereIn('station_id', Station::query()->where('location_id', $locationId)->whereNull('archived_at')->select('id'))
            ->pluck('station_id', 'item_category_id');
        $default = Station::query()->where('location_id', $locationId)->where('is_default', true)->whereNull('archived_at')->value('id');
        $out = [];

        foreach ($categories as $itemId => $categoryId) {
            $out[$itemId] = ($categoryId !== null ? $routes[$categoryId] ?? null : null) ?? $default;
        }

        return $out;
    }
}
