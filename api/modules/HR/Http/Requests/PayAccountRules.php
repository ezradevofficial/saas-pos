<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\PayAccount;

/** HR-04: pay account fields. */
final class PayAccountRules
{
    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'kind' => [$required, Rule::in(PayAccount::KINDS)],
            'provider' => [$required, 'string', 'max:100'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'account_name' => [$required, 'string', 'max:150'],
            'account_number' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9+ \-]{4,40}$/'],
            'currency' => [$required, 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
