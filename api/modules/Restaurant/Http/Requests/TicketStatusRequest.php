<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** POST restaurant/tickets/{id}/status (REST-09): `restaurant.ticket.update` at the ticket's location. */
class TicketStatusRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['new', 'preparing', 'ready', 'served'])]];
    }
}
