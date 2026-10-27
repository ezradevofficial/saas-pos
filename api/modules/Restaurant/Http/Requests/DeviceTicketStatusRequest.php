<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST restaurant/device/tickets/{id}/status (REST-09): a till's device token; the person must hold `restaurant.ticket.update` at its location (checked by the action). */
class DeviceTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['new', 'preparing', 'ready', 'served'])], 'user_id' => ['required', 'uuid']];
    }
}
