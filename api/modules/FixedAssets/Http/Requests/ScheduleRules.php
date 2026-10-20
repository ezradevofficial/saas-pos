<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Maintenance\Maintenance;

/** FA-19: the rules of maintenance schedules and work orders. */
final class ScheduleRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'string', 'max:160'],
            'basis' => [$need, 'string', Rule::in(['time', 'usage'])],
            'interval_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'interval_units' => ['sometimes', 'nullable', 'decimal:0,6', 'gt:0', 'max:99999999999999'],
            'warning_days' => ['sometimes', 'integer', 'between:0,365'],
            'last_done_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'last_done_reading' => ['sometimes', 'nullable', 'decimal:0,6', 'min:0', 'max:99999999999999'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function order(bool $required): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'schedule_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('fa_maintenance_schedules', 'id')],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'vendor_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'status' => ['sometimes', 'string', Rule::in(Maintenance::STATUSES)],
            'completed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reading' => ['sometimes', 'nullable', 'decimal:0,6', 'min:0', 'max:99999999999999'],
            'cost_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9000000000000000'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
