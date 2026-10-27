<?php

namespace Modules\Restaurant\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Http\Requests\TicketStatusRequest;
use Modules\Restaurant\Http\Requests\ViewRequest;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Orders\OrderView;
use Modules\Restaurant\Orders\Tickets;
use Modules\Restaurant\Support\Places;

/** REST-09, REST-12: the kitchen display's feed and a ticket's status. */
class TicketController
{
    public function __construct(private readonly Places $places, private readonly OrderView $view, private readonly Tickets $tickets) {}

    /** `status=active` (default): new, preparing and ready; `all`: also served (the last day, or since a time). */
    public function index(ViewRequest $request): JsonResponse
    {
        $query = Ticket::query()->with(['lines', 'order', 'station'])->orderBy('created_at')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        foreach (['location_id', 'station_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $status = $request->input('status', 'active');

        if ($status === 'active') {
            $query->whereIn('status', ['new', 'preparing', 'ready']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('since')) {
            $query->where('updated_at', '>', CarbonImmutable::parse((string) $request->input('since')));
        } elseif ($status === 'all') {
            $query->where('created_at', '>=', now()->subDay());
        }

        return response()->json(['data' => $query->limit(300)->get()->map(fn (Ticket $t) => $this->view->ticket($t))->all()]);
    }

    public function status(TicketStatusRequest $request, Ticket $restTicket): JsonResponse
    {
        $this->tickets->setStatus($restTicket, (string) $request->validated('status'), $request->user());

        return response()->json(['data' => $this->view->ticket($restTicket->refresh())]);
    }
}
