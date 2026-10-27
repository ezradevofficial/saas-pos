<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** POST restaurant/tickets/{id}/status (REST-09): `restaurant.ticket.update` (any move) or `restaurant.ticket.serve` (only to served) at the ticket's location. */
class TicketStatusRequest extends BoundRequest
{
    /** REST-09: `restaurant.ticket.serve` allows the move to `served` and nothing else; `update` allows every move. */
    public function permission(): string
    {
        return TicketPermission::for($this->user(), $this->input('status'));
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['new', 'preparing', 'ready', 'served'])]];
    }
}
