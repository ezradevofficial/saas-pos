<?php

namespace Modules\VanSales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST van-sales/device/sync (VAN-27): a paired van device's token (the
 * route's middleware); the place is the device's, never the body's. At most
 * 100 records in all; every id is read under the device's tenant.
 */
class DeviceSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = ['required', 'regex:/^\d{1,15}$/'];
        $qty = ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];

        return [
            'runs' => ['sometimes', 'array', 'max:100'],
            'runs.*.id' => ['required', 'uuid'],
            'runs.*.route_id' => ['nullable', 'uuid'],
            'runs.*.user_id' => ['required', 'uuid'],
            'runs.*.started_at' => ['required', 'date'],

            'visits' => ['sometimes', 'array', 'max:100'],
            'visits.*.id' => ['required', 'uuid'],
            'visits.*.run_id' => ['required', 'uuid'],
            'visits.*.stop_id' => ['nullable', 'uuid'],
            'visits.*.customer_id' => ['required', 'uuid'],
            'visits.*.user_id' => ['required', 'uuid'],
            'visits.*.status' => ['required', Rule::in(['visited', 'sold', 'no_sale', 'skipped'])],
            'visits.*.no_sale_reason' => ['nullable', Rule::in(['closed', 'no_need', 'has_stock', 'no_money', 'credit_hold', 'not_found', 'other'])],
            'visits.*.note' => ['nullable', 'string', 'max:500'],
            'visits.*.arrived_at' => ['nullable', 'date'],
            'visits.*.left_at' => ['nullable', 'date'],
            'visits.*.check_in' => ['nullable', 'array'],
            'visits.*.check_in.lat' => ['required_with:visits.*.check_in', 'numeric', 'between:-90,90'],
            'visits.*.check_in.lng' => ['required_with:visits.*.check_in', 'numeric', 'between:-180,180'],
            'visits.*.check_in.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'visits.*.check_in.at' => ['nullable', 'date'],
            'visits.*.updated_at' => ['required', 'date'],

            'collections' => ['sometimes', 'array', 'max:100'],
            'collections.*.id' => ['required', 'uuid'],
            'collections.*.run_id' => ['required', 'uuid'],
            'collections.*.visit_id' => ['nullable', 'uuid'],
            'collections.*.customer_id' => ['required', 'uuid'],
            'collections.*.user_id' => ['required', 'uuid'],
            'collections.*.payment_method_id' => ['required', 'uuid'],
            'collections.*.currency' => ['required', 'string', 'size:3'],
            'collections.*.amount_minor' => $money,
            'collections.*.provider_reference' => ['nullable', 'string', 'max:80'],
            'collections.*.receipt_seq' => ['required', 'integer', 'min:1'],
            'collections.*.receipt_number' => ['required', 'string', 'max:60'],
            'collections.*.number_range_id' => ['nullable', 'uuid'],
            'collections.*.collected_at' => ['required', 'date'],

            'counts' => ['sometimes', 'array', 'max:100'],
            'counts.*.id' => ['required', 'uuid'],
            'counts.*.run_id' => ['required', 'uuid'],
            'counts.*.user_id' => ['required', 'uuid'],
            'counts.*.submitted_at' => ['required', 'date'],
            'counts.*.note' => ['nullable', 'string', 'max:500'],
            'counts.*.lines' => ['nullable', 'array', 'max:500'],
            'counts.*.lines.*.item_id' => ['required', 'uuid'],
            'counts.*.lines.*.returned_qty' => $qty,
            'counts.*.lines.*.damaged_qty' => $qty,
            'counts.*.tenders' => ['nullable', 'array', 'max:40'],
            'counts.*.tenders.*.kind' => ['required', Rule::in(['cash', 'mobile_money'])],
            'counts.*.tenders.*.currency' => ['required', 'string', 'size:3'],
            'counts.*.tenders.*.counted_minor' => ['required', 'regex:/^\d{1,15}$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $total = collect(['runs', 'visits', 'collections', 'counts'])->sum(fn (string $key) => count((array) $this->input($key, [])));

            if ($total === 0 || $total > 100) {
                $validator->errors()->add('runs', __('van_sales.errors.batch_size'));
            }
        }];
    }
}
