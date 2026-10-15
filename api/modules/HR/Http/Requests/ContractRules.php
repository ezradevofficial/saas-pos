<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\Contract;

/** HR-07: contract fields. */
final class ContractRules
{
    public const PAY_INPUTS = ['pay_amount', 'currency', 'pay_basis'];

    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'type' => [$required, Rule::in(Contract::TYPES)],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'probation_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'position_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('hr_positions', 'id')],
            'department_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'pay_basis' => [$required, Rule::in(Contract::PAY_BASES)],
            'pay_amount' => [$required, 'string', 'regex:/^\d{1,13}(\.\d{1,4})?$/'],
            'currency' => [$required, 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'hours_per_week' => ['sometimes', 'nullable', 'numeric', 'between:0,168'],
        ];
    }
}
