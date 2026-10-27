<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST restaurant/orders/sync (REST-17): a paired till's device token (the
 * route's middleware); the place is the device's, never the body's. At most
 * 25 order records; every id is read under the device's tenant.
 */
class SyncOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'orders' => ['required', 'array', 'min:1', 'max:25'],
            'orders.*.id' => ['required', 'uuid'],
            'orders.*.kind' => ['required', Rule::in(['table', 'tab'])],
            'orders.*.table_id' => ['required_if:orders.*.kind,table', 'nullable', 'uuid'],
            'orders.*.tab_name' => ['nullable', 'string', 'max:120'],
            'orders.*.customer_id' => ['nullable', 'uuid'],
            'orders.*.waiter_id' => ['required', 'uuid'],
            'orders.*.covers' => ['nullable', 'integer', 'min:0', 'max:999'],
            'orders.*.currency' => ['required', 'string', 'size:3'],
            'orders.*.opened_at' => ['required', 'date'],
            'orders.*.updated_at' => ['required', 'date'],
            'orders.*.updated_by' => ['nullable', 'uuid'],
            'orders.*.note' => ['nullable', 'string', 'max:500'],
            'orders.*.bill_requested_at' => ['nullable', 'date'],
            'orders.*.status' => ['nullable', Rule::in(['open', 'cancelled'])],
            'orders.*.merged_into_id' => ['nullable', 'uuid'],
            'orders.*.actor_proof' => ['nullable', 'array'],
            'orders.*.lines' => ['nullable', 'array', 'max:300'],
            'orders.*.lines.*.id' => ['required', 'uuid'],
            'orders.*.lines.*.item_id' => ['required', 'uuid'],
            'orders.*.lines.*.name' => ['required', 'string', 'max:255'],
            'orders.*.lines.*.qty' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'orders.*.lines.*.unit_price_minor' => ['required', 'regex:/^\d{1,15}$/'],
            'orders.*.lines.*.tax_code_id' => ['nullable', 'uuid'],
            'orders.*.lines.*.tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'orders.*.lines.*.tax_inclusive' => ['nullable', 'boolean'],
            'orders.*.lines.*.course' => ['nullable', 'integer', 'min:1', 'max:9'],
            'orders.*.lines.*.seat' => ['nullable', 'integer', 'min:1', 'max:99'],
            'orders.*.lines.*.note' => ['nullable', 'string', 'max:200'],
            'orders.*.lines.*.modifiers' => ['nullable', 'array', 'max:30'],
            'orders.*.lines.*.modifiers.*.id' => ['nullable', 'string', 'max:64'],
            'orders.*.lines.*.modifiers.*.name' => ['required', 'string', 'max:120'],
            'orders.*.lines.*.modifiers.*.price_minor' => ['required', 'regex:/^\d{1,15}$/'],
            'orders.*.lines.*.created_at' => ['required', 'date'],
            'orders.*.lines.*.round' => ['nullable', 'integer', 'min:1', 'max:999'],
            'orders.*.lines.*.sent_at' => ['nullable', 'date'],
            'orders.*.lines.*.sent_by' => ['nullable', 'uuid'],
            'orders.*.lines.*.removed' => ['nullable', 'array'],
            'orders.*.lines.*.removed.at' => ['required_with:orders.*.lines.*.removed', 'date'],
            'orders.*.lines.*.removed.by' => ['required_with:orders.*.lines.*.removed', 'uuid'],
            'orders.*.lines.*.removed.reason' => ['nullable', 'string', 'max:255'],
            'orders.*.lines.*.removed.override' => ['nullable', 'array'],
            'orders.*.lines.*.removed.actor_proof' => ['nullable', 'array'],
            'orders.*.resends' => ['nullable', 'array', 'max:50'],
            'orders.*.resends.*.id' => ['required', 'uuid'],
            'orders.*.resends.*.line_ids' => ['required', 'array', 'min:1', 'max:300'],
            'orders.*.resends.*.line_ids.*' => ['uuid'],
            'orders.*.resends.*.at' => ['required', 'date'],
            'orders.*.resends.*.by' => ['nullable', 'uuid'],
        ];
    }
}
